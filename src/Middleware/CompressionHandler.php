<?php
/**
 * Copyright (c) Microsoft Corporation.  All Rights Reserved.
 * Licensed under the MIT License.  See License in the project root
 * for license information.
 */


namespace Microsoft\Kiota\Http\Middleware;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Microsoft\Kiota\Http\Middleware\Options\CompressionOption;
use Microsoft\Kiota\Http\Middleware\Options\ObservabilityOption;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Class CompressionHandler
 *
 * Compresses a request body using the provided callbacks in {@link CompressionOption}
 * Should the server return a 415, the CompressionHandler retries the request ONLY once with an uncompressed body
 *
 * @package Microsoft\Kiota\Http\Middleware
 * @copyright 2022 Microsoft Corporation
 * @license https://opensource.org/licenses/MIT MIT License
 * @link https://developer.microsoft.com/graph
 */
class CompressionHandler
{
    public const HANDLER_NAME = 'kiotaCompressionHandler';
    private const COMPRESSION_RETRY_ATTEMPT = 'compressionRetryAttempt';

    /**
     * @var CompressionOption {@link CompressionOption}
     */
    private CompressionOption $compressionOption;

    private TracerInterface $tracer;

    /**
     * @var callable(RequestInterface, array<string,mixed>): PromiseInterface
     * Next handler to be called in the middleware pipeline
     */
    private $nextHandler;

    /**
     * @var RequestInterface Initial request with uncompressed body
     */
    private RequestInterface $originalRequest;

    private const COMPRESSION_HANDLER_ENABLED_KEY = "com.microsoft.kiota.handler.compression.enable";
    /**
     * @param callable $nextHandler
     * @param CompressionOption|null $compressionOption
     */
    public function __construct(callable $nextHandler, ?CompressionOption $compressionOption = null)
    {
        $this->nextHandler = $nextHandler;
        $this->compressionOption = ($compressionOption) ?: new CompressionOption();
        $this->tracer = ObservabilityOption::getTracer();
    }

    /**
     * @param RequestInterface $request
     * @param array<string,mixed> $options
     * @return PromiseInterface
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $span = $this->tracer->spanBuilder('compressionHandler')
            ->startSpan();

        $scope = $span->activate();
        try {
            $span->setAttribute(self::COMPRESSION_HANDLER_ENABLED_KEY, true);
            // Request-level options override global options
            if (array_key_exists(CompressionOption::class, $options) && $options[CompressionOption::class] instanceof CompressionOption) {
                $this->compressionOption = $options[CompressionOption::class];
            }

            $this->originalRequest = $request; // keep reference in case we have to retry with uncompressed body
            if (!$this->isRetryAttempt($options)) {
                $request = $this->compress($request);
                $options['curl'] = [\CURLOPT_ENCODING => '']; // Allow curl to add the Accept-Encoding header with all the de-compression methods it supports
            }
            $fn = $this->nextHandler;
            return $fn($request, $options)->then(
                $this->onFulfilled($options, $span),
                $this->onRejected($options, $span)
            );
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * Returns true if the request's options indicate it's a retry attempt
     *
     * @param array<string,mixed> $options
     * @return bool
     */
    private function isRetryAttempt(array $options): bool
    {
        return (array_key_exists(self::COMPRESSION_RETRY_ATTEMPT, $options) && $options[self::COMPRESSION_RETRY_ATTEMPT] == 1);
    }

    private const COMPRESSION_RETRY_ATTEMPTS_KEY = 'compression_retry_attempts';

    /**
     * Retries the request if 415 response was received
     *
     * @param array<string, mixed> $options
     * @param SpanInterface $parentSpan
     * @return callable
     */
    private function onFulfilled(array $options, SpanInterface $parentSpan): callable
    {
        return function (ResponseInterface $response) use ($options, $parentSpan) {
            $span = $this->tracer->spanBuilder('compressionHandlerOnFulfilled')
                ->setParent(Context::getCurrent())
                ->addLink($parentSpan->getContext())
                ->startSpan();
            try {
                if ($response->getStatusCode() == 415 && !array_key_exists(self::COMPRESSION_RETRY_ATTEMPT, $options)) {
                    $options[self::COMPRESSION_RETRY_ATTEMPT] = 1;
                    $span->setAttribute(self::COMPRESSION_RETRY_ATTEMPTS_KEY, 1);
                    return $this($this->originalRequest, $options);
                }
                $span->setAttribute(self::COMPRESSION_RETRY_ATTEMPTS_KEY, ['retries' => $options[self::COMPRESSION_RETRY_ATTEMPT] ?? 1]);
                return $response;
            } finally {
                $span->end();
            }
        };
    }

    /**
     * Retry only if guzzle BadResponseException was thrown with a 415 status code
     *
     * @param array<string, mixed> $options
     * @param SpanInterface $parentSpan
     * @return callable
     */
    private function onRejected(array $options, SpanInterface $parentSpan): callable
    {
        return function ($reason) use ($options, $parentSpan) {
            $span = $this->tracer->spanBuilder('compressionHandlerOnRejected')
                ->setParent(Context::getCurrent())
                ->addLink($parentSpan->getContext())
                ->startSpan();
            try {
                // Only consider 415 BadResponseException in case guzzle http_errors = true
                if (is_a($reason, BadResponseException::class)) {
                    $span->recordException($reason);
                    if ($reason->getResponse()->getStatusCode() == 415 && !array_key_exists(self::COMPRESSION_RETRY_ATTEMPT, $options)) {
                        $options[self::COMPRESSION_RETRY_ATTEMPT] = 1;
                        return $this($this->originalRequest, $options);
                    }
                }
                return Create::rejectionFor($reason);
            } finally {
                $span->end();
            }
        };
    }

    /**
     * Applies compression callbacks provided in {@link CompressionOption} to the request
     *
     * @param RequestInterface $request
     * @return RequestInterface
     */
    private function compress(RequestInterface $request): RequestInterface
    {
        foreach ($this->compressionOption->getCallbacks() as $callback) {
            $request = $callback($request);
        }
        return $request;
    }
}
