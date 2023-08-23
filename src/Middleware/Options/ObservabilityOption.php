<?php

namespace Microsoft\Kiota\Http\Middleware\Options;

use Microsoft\Kiota\Abstractions\RequestOption;
use OpenTelemetry\API\Trace\TracerInterface;

class ObservabilityOption implements RequestOption
{
    private bool $includeEUIIAttributes = true;

    private bool $enabled;

    /**
     * @param bool $enabled
     */
    public function __construct(bool $enabled = false)
    {
        $this->enabled = $enabled;
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
        return $this->enabled;
    }

    /**
     * @param bool $enabled
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }
}
