<?php

namespace Microsoft\Kiota\Http\Middleware\Options;

use Microsoft\Kiota\Abstractions\RequestOption;

class ObservabilityOption implements RequestOption
{
    private static string $name = '';
    public bool $includeEUIIAttributes = false;
    public string $tracerInstrumentationName = '';

    /**
     * @return string
     */
    public function getTracerInstrumentationName(): string {
        return self::$name;
    }
}
