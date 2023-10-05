<?php

namespace Microsoft\Kiota\Http\Middleware;

use GuzzleHttp\Promise\PromiseInterface;
use Microsoft\Kiota\Abstractions\RequestOption;
use Microsoft\Kiota\Http\Middleware\Options\UserAgentHandlerOption;
use Psr\Http\Message\RequestInterface;

/**
 * @method PromiseInterface nextHandler(RequestInterface $request, array $options)
 */
class UserAgentHandler
{
    public const HANDLER_NAME = 'kiotaUserAgentHandler';
    public const USER_AGENT_HEADER_NAME = 'User-Agent';

    private UserAgentHandlerOption $userAgentHandlerOption;
    /** @var callable(RequestInterface, array<string, RequestOption>): PromiseInterface $nextHandler */
    private $nextHandler;

    /**
     * @param callable $nextHandler
     * @param UserAgentHandlerOption|null $agentHandlerOption
     */
    public function __construct(callable $nextHandler, ?UserAgentHandlerOption $agentHandlerOption)
    {
        $this->userAgentHandlerOption = $agentHandlerOption ?: new UserAgentHandlerOption();
        $this->nextHandler = $nextHandler;
    }

    /**
     * @param RequestInterface $request
     * @param array<string, RequestOption> $options
     * @return PromiseInterface
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        if (array_key_exists(UserAgentHandlerOption::class, $options) &&
            $options[UserAgentHandlerOption::class] instanceof UserAgentHandlerOption) {
            $this->userAgentHandlerOption = $options[UserAgentHandlerOption::class];
        }

        /** @var RequestInterface $request */
        $request = call_user_func($this->userAgentHandlerOption->getAgentConfigurator(), $request);
        /** @var PromiseInterface $result */
        $result = call_user_func($this->getNextHandler(), $request, $options);
        return $result;
    }

    /**
     * Get the next request handler.
     * @return callable(RequestInterface,array<string, RequestOption>): PromiseInterface
     */
    public function getNextHandler(): callable
    {
        return $this->nextHandler;
    }
}
