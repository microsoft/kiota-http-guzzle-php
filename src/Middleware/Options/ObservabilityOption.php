<?php

namespace Microsoft\Kiota\Http\Middleware\Options;

use Microsoft\Kiota\Abstractions\RequestOption;
use OpenTelemetry\API\Common\Instrumentation\Globals;
use OpenTelemetry\API\Trace\NoopTracer;
use OpenTelemetry\API\Trace\TracerInterface;

class ObservabilityOption implements RequestOption
{
    private bool $includeEUIIAttributes = true;

    private static bool $enabled;

    private static TracerInterface $tracer;

    /**
     * @param bool $enabled
     */
    public function __construct(bool $enabled = false)
    {
        self::$tracer = $enabled ? Globals::tracerProvider()->getTracer(self::getTracerInstrumentationName()): NoopTracer::getInstance();
        self::$enabled = $enabled;
    }

    /**
     * @return string
     */
    public function getTracerInstrumentationName(): string
    {
        return "microsoft/kiota-http-guzzle-php";
    }

    /**
     * @param bool $includeEUIIAttributes
     */
    public function setIncludeEUIIAttributes(bool $includeEUIIAttributes): void
    {
        $this->includeEUIIAttributes = $includeEUIIAttributes;
    }

    public function getIncludeEUIIAttributes(): bool
    {
        return $this->includeEUIIAttributes;
    }

    /**
     * @return bool
     */
    public function getEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * @param bool $enabled
     */
    public function setEnabled(bool $enabled): void
    {
        self::$tracer = $enabled ? Globals::tracerProvider()->getTracer($this->getTracerInstrumentationName()) : NoopTracer::getInstance();
        self::$enabled = $enabled;
    }

    public function setTracer(TracerInterface $tracer): void
    {
        if (!self::$enabled && self::$tracer instanceof NoopTracer) return;
        self::$tracer = $tracer;
    }

    /**
     * @return TracerInterface
     */
    public static function getTracer(): TracerInterface
    {
        return self::$tracer;
    }
}
