<?php
/**
 * Copyright (c) Microsoft Corporation.  All Rights Reserved.
 * Licensed under the MIT License.  See License in the project root
 * for license information.
 */


namespace Microsoft\Kiota\Http\Middleware;

use GuzzleHttp\Promise\PromiseInterface;
use Microsoft\Kiota\Http\Middleware\Options\TelemetryOption;
use Psr\Http\Message\RequestInterface;

/**
 * Class TelemetryHandler
 *
 * Middleware that allows configuration of a RequestInterface with telemetry data
 *
 * @package Microsoft\Kiota\Http\Middleware
 * @copyright 2022 Microsoft Corporation
 * @license https://opensource.org/licenses/MIT MIT License
 * @link https://developer.microsoft.com/graph
 */
class TelemetryHandler
{
    public const HANDLER_NAME = 'kiotaTelemetryHandler';

    /**
     * @var callable(RequestInterface, array<string, mixed>):PromiseInterface
     * Next handler in the handler stack
     */
    private $nextHandler;

    /**
     * @var TelemetryOption|null {@link TelemetryOption}
     */
    private ?TelemetryOption $telemetryOption;

    public function __construct(callable $nextHandler, ?TelemetryOption $telemetryOption = null)
    {
        $this->nextHandler = $nextHandler;
        $this->telemetryOption = $telemetryOption;
    }

    /**
     * @param RequestInterface $request
     * @param array<string,mixed> $options
     * @return PromiseInterface
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        // Request-level options override global options without mutating handler state
        $telemetryOption = $this->telemetryOption;
        if (array_key_exists(TelemetryOption::class, $options) && $options[TelemetryOption::class] instanceof TelemetryOption) {
            $telemetryOption = $options[TelemetryOption::class];
        }
        if ($telemetryOption && $telemetryOption->getTelemetryConfigurator()) {
            $request = $telemetryOption->getTelemetryConfigurator()($request);
        }
        $fn = $this->nextHandler;
        return $fn($request, $options);
    }

    /**
     * Get the next request handler.
     * @return callable(RequestInterface, array<string, mixed>): PromiseInterface
     */
    public function getNextHandler(): callable
    {
        return $this->nextHandler;
    }

    /**
     * Get the default telemetry option.
     * @return TelemetryOption|null
     */
    public function getTelemetryOption(): ?TelemetryOption
    {
        return $this->telemetryOption;
    }
}
