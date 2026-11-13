<?php
/**
 * Copyright (c) Microsoft Corporation.  All Rights Reserved.
 * Licensed under the MIT License.  See License in the project root
 * for license information.
 */


namespace Microsoft\Kiota\Http\Middleware;

use DateTime;
use DateTimeInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Microsoft\Kiota\Http\Middleware\Options\ObservabilityOption;
use Microsoft\Kiota\Http\Middleware\Options\RetryOption;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use function pow;

/**
 * Class RetryHandler
 *
 * Middleware that retries requests based on {@link RetryOption} while respecting the Retry-After header
 *
 * @package Microsoft\Kiota\Http\Middleware
 * @copyright 2021 Microsoft Corporation
 * @license https://opensource.org/licenses/MIT MIT License
 * @link https://developer.microsoft.com/graph
 */
class RetryHandler
{
    public const HANDLER_NAME = 'kiotaRetryHandler';
    private const RETRY_AFTER_HEADER = "Retry-After";
    private const RETRY_ATTEMPT_HEADER = "Retry-Attempt";
    private const RESEND_COUNT_ATTRIBUTE = "http.request.resend_count";
    private const RESEND_DELAY_ATTRIBUTE = "http.request.resend_delay";
    private const STATUS_CODE_ATTRIBUTE = "http.response.status_code";
    
    /**
     * @var TracerInterface
     */
    private TracerInterface $tracer;
    /**
     * @var RetryOption configuration options for the middleware
     */
    private RetryOption $retryOption;
    /**
     * @var callable(RequestInterface, array<string,mixed>): PromiseInterface $nextHandler
     */
    private $nextHandler;

    /**
     * @param RetryOption|null $retryOption
     * @param callable $nextHandler
     */
    public function __construct(callable $nextHandler, ?RetryOption $retryOption = null)
    {
        $this->retryOption = ($retryOption) ?: new RetryOption();
        $this->tracer = ObservabilityOption::getTracer();
        $this->nextHandler = $nextHandler;
    }

    private const RETRY_HANDLER_INVOKED = "retryHandlerInvoked";
    private const RETRY_HANDLER_ENABLED_KEY = 'com.microsoft.kiota.handler.retry.enable';
    /**
     * @param RequestInterface $request
     * @param array<string, mixed> $options
     * @return PromiseInterface
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $span = $this->tracer->spanBuilder(self::RETRY_HANDLER_INVOKED)->startSpan();
        $span->addEvent(self::RETRY_HANDLER_INVOKED);
        $scope = $span->activate();
        try {
            $span->setAttribute(self::RETRY_HANDLER_ENABLED_KEY, true);
            // Request-level options override global options without mutating handler state
            $retryOption = $this->retryOption;
            if (array_key_exists(RetryOption::class, $options) && $options[RetryOption::class] instanceof RetryOption) {
                $retryOption = $options[RetryOption::class];
            }
            $fn = $this->nextHandler;
            return $fn($request, $options)->then(
                $this->onFulfilled($request, $options, $span, $retryOption),
                $this->onRejected($request, $options, $span, $retryOption)
            );
        } finally {
            $scope->detach();
            $span->end();
        }

    }

    /**
     * Handles retry for a successful request
     *
     * @param RequestInterface $request
     * @param array<string, mixed> $options
     * @param SpanInterface $span
     * @param RetryOption|null $retryOption
     * @return callable
     */
    private function onFulfilled(RequestInterface $request, array $options, SpanInterface $span, ?RetryOption $retryOption = null): callable
    {
        $retryOption = $retryOption ?: $this->retryOption;
        return function (?ResponseInterface $response) use ($request, $options, $span, $retryOption) {
            $fullFilledSpan = $this->tracer->spanBuilder('onFullFilled')
                ->addLink($span->getContext())
                ->setParent(Context::getCurrent())
                ->startSpan();
            try {
                if (!$response) {
                    return $response;
                }
                $retries = $this->getRetries($request);
                $span->setAttribute(self::RESEND_COUNT_ATTRIBUTE, $retries);
                $delaySecs = $this->calculateDelay($retries, $response, $retryOption);
                $span->setAttribute(self::RESEND_DELAY_ATTRIBUTE, $delaySecs);
                $statusCode = $response->getStatusCode();
                $span->setAttribute(self::STATUS_CODE_ATTRIBUTE, $statusCode);
                $fullFilledSpan->setStatus(StatusCode::STATUS_OK, 'RetryFullFilled');
                if (!$this->shouldRetry($request, $retries, $delaySecs, $response, $span, $retryOption)
                    || $this->exceedRetriesTimeLimit($delaySecs, $retryOption)) {
                    return $response;
                }
                $options['delay'] = $delaySecs * 1000; // Guzzle sleeps the thread before executing request
                $request = $request->withHeader(self::RETRY_ATTEMPT_HEADER, (string)($retries + 1));
                $request->getBody()->rewind();
                return $this($request, $options);
            } finally {
                $fullFilledSpan->end();
            }
        };
    }

    /**
     * Handles retry if {@link BadResponseException} is thrown by Guzzle
     * BadResponseException is thrown for 4xx and 5xx responses if configured on Guzzle client
     *
     * @param RequestInterface $request
     * @param array<string,mixed> $options
     * @param SpanInterface $span
     * @param RetryOption|null $retryOption
     * @return callable
     */
    private function onRejected(RequestInterface $request, array $options, SpanInterface $span, ?RetryOption $retryOption = null): callable
    {
        $retryOption = $retryOption ?: $this->retryOption;
        return function ($reason) use ($request, $options, $span, $retryOption) {
            $rejectedSpan = $this->tracer->spanBuilder('onRejected')
                ->addLink($span->getContext())
                ->setParent(Context::getCurrent())
                ->startSpan();
            $scope = $rejectedSpan->activate();
            try {
                $rejectedSpan->setStatus(StatusCode::STATUS_ERROR, 'RejectedRetry');
                $rejectedSpan->recordException($reason);
                // No retry for network-related/other exceptions
                if (!is_a($reason, BadResponseException::class)) {
                    return Create::rejectionFor($reason);
                }

                $retries = $this->getRetries($request);
                $rejectedSpan->setAttribute(self::RESEND_COUNT_ATTRIBUTE, $retries);
                $delaySecs = $this->calculateDelay($retries, $reason->getResponse(), $retryOption);
                $rejectedSpan->setAttribute(self::RESEND_DELAY_ATTRIBUTE, $delaySecs);
                $statusCode = $reason->getResponse()->getStatusCode();
                $span->setAttribute(self::STATUS_CODE_ATTRIBUTE, $statusCode);
                if (!$this->shouldRetry($request, $retries, $delaySecs, $reason->getResponse(), $span, $retryOption)
                    || $this->exceedRetriesTimeLimit($delaySecs, $retryOption)) {
                    return Create::rejectionFor($reason);
                }
                $options['delay'] = $delaySecs * 1000; // Guzzle sleeps the thread before executing request
                $request = $request->withHeader(self::RETRY_ATTEMPT_HEADER, (string)($retries + 1));
                $request->getBody()->rewind();
                return $this($request, $options);
            } finally {
                $scope->detach();
                $rejectedSpan->end();
            }
        };
    }

    /**
     * Returns true if request should be retried
     *
     * @param RequestInterface $request
     * @param int $retries
     * @param int $delaySecs
     * @param ResponseInterface|null $response
     * @param SpanInterface $span
     * @param RetryOption|null $retryOption
     * @return bool
     */
    private function shouldRetry(RequestInterface $request, int $retries, int $delaySecs, ?ResponseInterface $response, SpanInterface $span, ?RetryOption $retryOption = null): bool
    {
        $retryOption = $retryOption ?: $this->retryOption;
        $shouldRetryValue = ((($retries < $retryOption->getMaxRetries())
                    && $this->isPayloadRewindable($request)
                    && ($response && $retryOption->getShouldRetry()($delaySecs, $retries, $response))
                    && $this->isRetryStatusCode($response->getStatusCode())
                    && $this->isRetriableForMethod($request, $response, $retryOption)) || !$response
        );
        $span->setAttribute('shouldRetry', $shouldRetryValue);
        return $shouldRetryValue;
    }

    /**
     * Get number of retries from the $request Retry-Attempt header
     *
     * @param RequestInterface $request
     * @return int
     */
    private function getRetries(RequestInterface $request): int
    {
        if ($request->hasHeader(self::RETRY_ATTEMPT_HEADER)) {
            return intval($request->getHeader(self::RETRY_ATTEMPT_HEADER)[0]);
        }
        return 0;
    }

    /**
     * Determine delay in seconds based on $retryOptions, total number of retries and Retry-After header value
     *
     * @param int $retries
     * @param ResponseInterface|null $response
     * @param RetryOption|null $retryOption
     * @return int seconds to delay
     */
    private function calculateDelay(int $retries, ?ResponseInterface $response, ?RetryOption $retryOption = null): int
    {
        $retryOption = $retryOption ?: $this->retryOption;
        $retryAfterSeconds = 0;
        if ($response && $response->hasHeader(self::RETRY_AFTER_HEADER)) {
            $retryAfterSeconds = $this->parseRetryAfterToSeconds($response->getHeader(self::RETRY_AFTER_HEADER)[0]);
        }

        // First retry attempt
        if ($retries == 0) {
            return ($retryOption->getDelay() > $retryAfterSeconds) ? $retryOption->getDelay() : $retryAfterSeconds;
        }

        $retries++;
        $expoDelay = self::exponentialDelay($retries, $retryOption->getDelay());
        return ($expoDelay > $retryAfterSeconds) ? $expoDelay : $retryAfterSeconds;
    }

    /**
     * Returns true if $delaySeconds exceeds {@link RetryOption} retriesTimeLimit
     *
     * @param int $delaySecs
     * @param RetryOption|null $retryOption
     * @return bool
     */
    private function exceedRetriesTimeLimit(int $delaySecs, ?RetryOption $retryOption = null): bool
    {
        $retryOption = $retryOption ?: $this->retryOption;
        if (!$retryOption->getRetriesTimeLimit()) {
            return false;
        }
        // Add intervals to 01 Jan 1970 00:00:00
        $retriesLimitSecs = date_create("@0")->add($retryOption->getRetriesTimeLimit())->getTimestamp();
        $processingSecs = date_create("@0")->getTimestamp() + $delaySecs;
        return ($processingSecs > $retriesLimitSecs);
    }

    /**
     * Returns true if Http Status Code warrants a retry
     *
     * @param int $httpStatusCode
     * @return bool
     */
    private function isRetryStatusCode(int $httpStatusCode): bool
    {
        return ($httpStatusCode == 429 || $httpStatusCode == 503 || $httpStatusCode == 504);
    }

    /**
     * Returns true if the response allows resending the request, given its method.
     * With retryUnprocessedMutationsOnly set, POST and PATCH requests are retried only after a response
     * showing the server didn't process them, because a 504 may arrive after the server already acted on the request.
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @param RetryOption $retryOption
     * @return bool
     */
    private function isRetriableForMethod(RequestInterface $request, ResponseInterface $response, RetryOption $retryOption): bool
    {
        if (!$retryOption->getRetryUnprocessedMutationsOnly()) {
            return true;
        }
        $method = strtoupper($request->getMethod());
        if ($method !== 'POST' && $method !== 'PATCH') {
            return true;
        }
        // By common service convention, a 429 or 503 means the server didn't process the request,
        // so resending it is treated as safe.
        $statusCode = $response->getStatusCode();
        return ($statusCode == 429 || $statusCode == 503);
    }

    /**
     * Returns true if request payload is a rewindable stream
     *
     * @param RequestInterface $request
     * @return bool
     */
    private function isPayloadRewindable(RequestInterface $request): bool
    {
        return $request->getBody()->isSeekable();
    }

    /**
     * Parses Http Retry-After values of type <http-date> of <delay-seconds>
     *
     * @param string $retryAfterValue Retry-After value formatted as <http-date> or <delay-seconds>
     * @return int number of seconds
     */
    private function parseRetryAfterToSeconds(string $retryAfterValue): int
    {
        if (is_numeric($retryAfterValue)) {
            return intval($retryAfterValue);
        }
        $retryAfterDateTime = DateTime::createFromFormat(DateTimeInterface::RFC7231, $retryAfterValue);
        if (!$retryAfterDateTime) {
            throw new RuntimeException("Unable to parse Retry-After header value $retryAfterValue");
        }
        return $retryAfterDateTime->getTimestamp() - (new DateTime())->getTimestamp();
    }

    /**
     * Exponential backoff delay
     *
     * @param int $retries
     * @param int $delaySecs
     * @return int
     */
    public static function exponentialDelay(int $retries, int $delaySecs): int
    {
        return (int) pow(2, $retries - 1) * $delaySecs;
    }
}
