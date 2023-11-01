<?php

namespace Microsoft\Kiota\Http\Middleware\Options;

use Microsoft\Kiota\Abstractions\RequestOption;
use Microsoft\Kiota\Http\Constants;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\TracerInterface;

class ObservabilityOption implements RequestOption
{
    private bool $includeEUIIAttributes = true;

    private static ?TracerInterface $tracer = null;

    /**
     */
    public function __construct()
    {
        self::$tracer = Globals::tracerProvider()->getTracer(self::getTracerInstrumentationName(), Constants::KIOTA_HTTP_CLIENT_VERSION);
    }

    /**
     * @return string
     */
    public static function getTracerInstrumentationName(): string
    {
        return "microsoft.kiota.http:kiota-http-guzzle-php";
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

    public static function setTracer(TracerInterface $tracer): void
    {
        self::$tracer = $tracer;
    }

    /**
     * @return TracerInterface
     */
    public static function getTracer(): TracerInterface
    {
        if (self::$tracer === null) {
            self::$tracer = Globals::tracerProvider()->getTracer(self::getTracerInstrumentationName(),
                Constants::KIOTA_HTTP_CLIENT_VERSION);
        }
        return self::$tracer;
    }
}
