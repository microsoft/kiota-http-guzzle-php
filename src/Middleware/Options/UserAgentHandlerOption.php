<?php

namespace Microsoft\Kiota\Http\Middleware\Options;

use GuzzleHttp\Promise\PromiseInterface;
use Microsoft\Kiota\Abstractions\RequestOption;
use Microsoft\Kiota\Http\Constants;
use Microsoft\Kiota\Http\Middleware\UserAgentHandler;
use Psr\Http\Message\RequestInterface;

class UserAgentHandlerOption implements RequestOption
{
    private string $productName = "kiota-php";
    private string $productVersion = Constants::KIOTA_HTTP_CLIENT_VERSION;
    private bool $enabled = true;

    /** @var callable(RequestInterface $request): PromiseInterface|null */
    private $agentConfigurator;

    public function __construct(?callable $userAgentConfigurator = null)
    {
        $this->agentConfigurator = $userAgentConfigurator;
    }

    /**
     * Gets the product name.
     * @return string
     */
    public function getProductName(): string
    {
        return $this->productName;
    }

    /**
     * Gets the Product version
     * @return string
     */
    public function getProductVersion(): string
    {
        return $this->productVersion;
    }

    /**
     * Returns whether the handler is enabled or not.
     * @return bool
     */
    public function getEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Sets enabled to true or false.
     * @param bool $enabled
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Sets the Product name
     * @param string $productName
     */
    public function setProductName(string $productName): void
    {
        $this->productName = $productName;
    }

    /**
     *  Sets the product version.
     * @param string $productVersion
     */
    public function setProductVersion(string $productVersion): void
    {
        $this->productVersion = $productVersion;
    }

    /**
     * Sets the callable to configure user agent.
     * @param callable $agentConfigurator
     */
    public function setAgentConfigurator(callable $agentConfigurator): void
    {
        $this->agentConfigurator = $agentConfigurator;
    }

    /**
     * Gets the callable to configure UserAgent
     * @return callable
     */
    public function getAgentConfigurator(): callable
    {
        return $this->agentConfigurator ?? $this->initUserAgentConfigurator();
    }

    /**
     * Returns the Product Name and Product version separated by '/'
     * @return string
     */
    public function getUserAgentHeaderValue(): string
    {
        return $this->getProductName() . '/' . $this->getProductVersion();
    }

    /**
     * Initializes a User agent configurator for use in cases where no
     * configurator is specified
     * @return callable
     */
    public function initUserAgentConfigurator(): callable
    {
        return function (RequestInterface $request) {
            $currentUserAgentHeaderValue = $request->getHeaderLine(UserAgentHandler::USER_AGENT_HEADER_NAME);
            if ($currentUserAgentHeaderValue) {
                $currentUserAgentHeaderValue .= " ";
            }
            $currentUserAgentHeaderValue .= $this->getUserAgentHeaderValue();
            return $request->withHeader(UserAgentHandler::USER_AGENT_HEADER_NAME, $currentUserAgentHeaderValue);
        };
    }
}
