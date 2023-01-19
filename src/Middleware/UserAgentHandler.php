<?php

namespace Microsoft\Kiota\Http\Middleware;

use GuzzleHttp\Promise\PromiseInterface;
use Microsoft\Kiota\Http\Middleware\Options\UserAgentHandlerOption;
use Psr\Http\Message\RequestInterface;

/**
 * @method PromiseInterface nextHandler(RequestInterface $request, array $options)
 */
class UserAgentHandler
{
    public const USER_AGENT_HEADER_NAME = 'User-Agent';

    private UserAgentHandlerOption $userAgentHandlerOption;
    /** @var callable(RequestInterface, array): PromiseInterface $nextHandler  */
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

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        if (array_key_exists(UserAgentHandlerOption::class, $options)) {
            $this->userAgentHandlerOption = $options[UserAgentHandlerOption::class];
        }

        if ($this->userAgentHandlerOption->getAgentConfigurator()) {
            $request = call_user_func($this->userAgentHandlerOption->getAgentConfigurator(), $request);
        }
        $fn = $this->getNextHandler();
        return call_user_func($fn, $request, $options);
    }

    /**
     * @return callable
     */
    public function getNextHandler(): callable
    {
        return $this->nextHandler;
    }
}
