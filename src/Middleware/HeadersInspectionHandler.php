<?php
/**
 * Copyright (c) Microsoft Corporation.  All Rights Reserved.
 * Licensed under the MIT License.  See License in the project root
 * for license information.
 */


namespace Microsoft\Kiota\Http\Middleware;

use GuzzleHttp\Promise\PromiseInterface;
use Microsoft\Kiota\Abstractions\RequestHeaders;
use Microsoft\Kiota\Http\Middleware\Options\HeadersInspectionHandlerOption;
use Microsoft\Kiota\Http\Middleware\Options\ObservabilityOption;
use OpenTelemetry\API\Trace\TracerInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Class HeadersInspectionHandler
 *
 * Handler that allows you to get the raw request and response headers while still using the default deserialization
 * of response bodies to models. Disabled by default. Configured using a {@link HeadersInspectionHandlerOption}
 *
 * @package Microsoft\Kiota\Http\Middleware
 * @copyright 2023 Microsoft Corporation
 * @license https://opensource.org/licenses/MIT MIT License
 */
class HeadersInspectionHandler
{
    public const HANDLER_NAME = "kiotaHeadersInspectionHandler";
    private const SPAN_NAME = "HeadersInspectionHandler_invoke?";
    private const HANDLER_ENABLED_KEY = "com.microsoft.kiota.handler.headers_inspection.enable";

    /**
     * @var HeadersInspectionHandlerOption
     */
    private HeadersInspectionHandlerOption $headersInspectionOption;

    /**
     * @var callable(RequestInterface, array<string,mixed>): PromiseInterface
     */
    private $nextHandler;

    /**
     * @var TracerInterface
     */
    private TracerInterface $tracer;

    /**
     * @param callable $nextHandler
     * @param HeadersInspectionHandlerOption|null $headersInspectionOption
     */
    public function __construct(callable $nextHandler, ?HeadersInspectionHandlerOption $headersInspectionOption = null)
    {
        $this->nextHandler = $nextHandler;
        $this->headersInspectionOption = ($headersInspectionOption) ?: new HeadersInspectionHandlerOption();
        $this->tracer = ObservabilityOption::getTracer();
    }

    /**
     * @param RequestInterface $request
     * @param array<string, mixed> $options
     * @return PromiseInterface
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $span = $this->tracer->spanBuilder(self::SPAN_NAME)->startSpan();
        $scope = $span->activate();
        $span->setAttribute(self::HANDLER_ENABLED_KEY, true);

        try {
            if (array_key_exists(HeadersInspectionHandlerOption::class, $options)
                && $options[HeadersInspectionHandlerOption::class] instanceof HeadersInspectionHandlerOption
            ) {
                $this->headersInspectionOption = $options[HeadersInspectionHandlerOption::class];
            }

            if ($this->headersInspectionOption->isInspectRequestHeaders()) {
                $requestHeaders = new RequestHeaders();
                $requestHeaders->putAll($request->getHeaders());
                $this->headersInspectionOption->setRequestHeaders($requestHeaders);
            }

            $fn = $this->nextHandler;
            return $fn($request, $options)->then(
                function (?ResponseInterface $response) {
                    if (!$response) {
                        return $response;
                    }
                    if ($this->headersInspectionOption->isInspectResponseHeaders()) {
                        $responseHeaders = new RequestHeaders();
                        $responseHeaders->putAll($response->getHeaders());
                        $this->headersInspectionOption->setResponseHeaders($responseHeaders);
                    }
                    return $response;
                }
            );
        } finally {
            $scope->detach();
            $span->end();
        }
    }

}
