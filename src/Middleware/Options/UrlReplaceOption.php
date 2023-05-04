<?php

namespace Microsoft\Kiota\Http\Middleware\Options;

use Microsoft\Kiota\Abstractions\RequestOption;

class UrlReplaceOption implements RequestOption
{
    private bool $enabled = true;
    /** @var array<string, string> */
    private array $replacementPairs = [];

    /**
     * @param bool $enabled
     * @param array<string, string> $replacementPairs
     */
    public function __construct(bool $enabled, array $replacementPairs)
    {
        $this->enabled = $enabled;
        $this->replacementPairs = $replacementPairs;
    }

    /**
     * @return array<string,string>
     */
    public function getReplacementPairs(): array
    {
        return $this->replacementPairs;
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param array<string,string> $replacementPairs
     */
    public function setReplacementPairs(array $replacementPairs): void
    {
        $this->replacementPairs = $replacementPairs;
    }

    /**
     * @param bool $enabled
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }
}
