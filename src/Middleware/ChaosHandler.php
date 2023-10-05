<?php
/**
 * Copyright (c) Microsoft Corporation.  All Rights Reserved.
 * Licensed under the MIT License.  See License in the project root
 * for license information.
 */


namespace Microsoft\Kiota\Http\Middleware;

use Exception;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Microsoft\Kiota\Http\Middleware\Options\ChaosOption;
use Microsoft\Kiota\Http\Middleware\Options\ObservabilityOption;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Class ChaosHandler
 *
 * Middleware that selects a chaos response (configured via {@link ChaosOption}) at random x% of the time
 * If criteria is not met for a chaos response, the request is forwarded down the middleware chain
 *
 * @package Microsoft\Kiota\Http\Middleware
 * @copyright 2022 Microsoft Corporation
 * @license https://opensource.org/licenses/MIT MIT License
 * @link https://developer.microsoft.com/graph
 */
class ChaosHandler
{
    /** @const string CHAOS_HANDLER_TRIGGERED_EVENT_KEY */
    private const CHAOS_HANDLER_TRIGGERED_EVENT_KEY = "chaos_handler_triggered";
    private const CHAOS_HANDLER_ENABLED_KEY = 'com.microsoft.kiota.handler.chaos.enable';
    public const HANDLER_NAME = 'kiotaChaosHandler';

    private TracerInterface $tracer;
    /**
     * @var callable Next handler in the middleware pipeline
     */
    private $nextHandler;

    /**
     * @var ChaosOption {@link ChaosOption}
     */
    private ChaosOption $chaosOption;

    /**
     * @param callable $nextHandler
     * @param ChaosOption|null $chaosOption
     */
    public function __construct(callable $nextHandler, ?ChaosOption $chaosOption = null)
    {
        $this->tracer = ObservabilityOption::getTracer();
        $this->nextHandler = $nextHandler;
        $this->chaosOption = ($chaosOption) ?: new ChaosOption();
    }

    /**
     * @param RequestInterface $request
     * @param array<string,mixed> $options
     * @return PromiseInterface
     * @throws Exception
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $span = $this->tracer->spanBuilder('chaosHandlerSpan')
            ->startSpan();
        $span->addEvent(self::CHAOS_HANDLER_TRIGGERED_EVENT_KEY);
        $scope = $span->activate();
        try {
            $span->setAttribute(self::CHAOS_HANDLER_ENABLED_KEY, true);
            // Request-level options override global options
            if (array_key_exists(ChaosOption::class, $options) && $options[ChaosOption::class] instanceof ChaosOption) {
                $this->chaosOption = $options[ChaosOption::class];
            }

            $randomPercentage = random_int(1, ChaosOption::MAX_CHAOS_PERCENTAGE);
            if ($randomPercentage <= $this->chaosOption->getChaosPercentage()) {
                $response = $this->randomChaosResponse($request, $options, $span);
                if ($response) {
                    return Create::promiseFor($response);
                }
            }
            $fn = $this->nextHandler;
            return $fn($request, $options);
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * Selects a chaos response from ChaosOptions at random or falls back to selecting a random response
     * from pre-configured possible responses per HTTP request method
     *
     * @param RequestInterface $request
     * @param array<string, mixed> $options
     * @param SpanInterface $parentSpan
     * @return ResponseInterface|null
     * @throws Exception
     */
    private function randomChaosResponse(RequestInterface $request, array $options, SpanInterface $parentSpan): ?ResponseInterface
    {
        $span = $this->tracer->spanBuilder('randomChaosResponse')
            ->setParent(Context::getCurrent())
            ->addLink($parentSpan->getContext())
            ->startSpan();
        try {
            $chaosResponses = $this->chaosOption->getChaosResponses();
            if (empty($chaosResponses)) {
                $chaosResponses = $this->getRandomResponsesByRequestMethod($request->getMethod(), $span);
            }
            if (!$chaosResponses) {
                return null;
            }
            $randomIndex = random_int(0, sizeof($chaosResponses) - 1);
            $chaosResponse = $chaosResponses[$randomIndex];
            return is_callable($chaosResponse) ? $chaosResponse($request, $options) : $chaosResponse;
        } finally {
            $span->end();
        }
    }

    private const INVALID_HTTP_METHOD_ATTRIBUTE = 'invalidHttpMethodPassedToRandomResponseMethodGenerator';

    /**
     * Returns list of possible responses by HTTP request method
     *
     * @param string $httpMethod
     * @param SpanInterface $parentSpan
     * @return Response[]|null
     */
    private function getRandomResponsesByRequestMethod(string $httpMethod, SpanInterface $parentSpan): ?array
    {
        $span = $this->tracer->spanBuilder('getRandomResponsesByRequestMethod')
            ->setParent(Context::getCurrent())
            ->addLink($parentSpan->getContext())
            ->startSpan();
        try {
            $randomResponses = [
                'GET'    => [
                    new Response(200),
                    new Response(301),
                    new Response(307),
                    new Response(400),
                    new Response(401),
                    new Response(403),
                    new Response(404),
                    new Response(405),
                    new Response(429),
                    new Response(500),
                    new Response(502),
                    new Response(503),
                    new Response(504)
                ],
                'POST'   => [
                    new Response(200),
                    new Response(201),
                    new Response(204),
                    new Response(307),
                    new Response(400),
                    new Response(401),
                    new Response(403),
                    new Response(404),
                    new Response(405),
                    new Response(429),
                    new Response(500),
                    new Response(502),
                    new Response(503),
                    new Response(504),
                    new Response(507)
                ],
                'PUT'    => [
                    new Response(200),
                    new Response(201),
                    new Response(400),
                    new Response(401),
                    new Response(403),
                    new Response(404),
                    new Response(405),
                    new Response(409),
                    new Response(429),
                    new Response(500),
                    new Response(502),
                    new Response(503),
                    new Response(504),
                    new Response(507)
                ],
                'PATCH'  => [
                    new Response(200),
                    new Response(204),
                    new Response(400),
                    new Response(401),
                    new Response(403),
                    new Response(404),
                    new Response(405),
                    new Response(429),
                    new Response(500),
                    new Response(502),
                    new Response(503),
                    new Response(504)
                ],
                'DELETE' => [
                    new Response(200),
                    new Response(204),
                    new Response(400),
                    new Response(401),
                    new Response(403),
                    new Response(404),
                    new Response(405),
                    new Response(429),
                    new Response(500),
                    new Response(502),
                    new Response(503),
                    new Response(504),
                    new Response(507)
                ]
            ];

            if (!array_key_exists(strtoupper($httpMethod), $randomResponses)) {
                $span->setAttribute(self::INVALID_HTTP_METHOD_ATTRIBUTE, ['method' => $httpMethod]);
                return null;
            }
            return $randomResponses[strtoupper($httpMethod)];
        } finally {
            $span->end();
        }
    }
}
