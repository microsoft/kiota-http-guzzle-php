<?php
/**
 * Copyright (c) Microsoft Corporation.  All Rights Reserved.
 * Licensed under the MIT License.  See License in the project root
 * for license information.
 */


namespace Microsoft\Kiota\Http;


use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware as GuzzleMiddleware;
use GuzzleHttp\Utils;
use Microsoft\Kiota\Http\Middleware\HeadersInspectionHandler;
use Microsoft\Kiota\Http\Middleware\KiotaMiddleware;
use Microsoft\Kiota\Http\Middleware\ParametersNameDecodingHandler;
use Microsoft\Kiota\Http\Middleware\RetryHandler;
use Microsoft\Kiota\Http\Middleware\UserAgentHandler;

/**
 * Class KiotaClientFactory
 *
 * This class is used to build the \GuzzleHttp\Client instance used by the core service.
 *
 * @package Microsoft\Kiota\Http
 * @copyright 2022 Microsoft Corporation
 * @license https://opensource.org/licenses/MIT MIT License
 * @link https://developer.microsoft.com/graph
 */
class KiotaClientFactory
{
    /**
     * Initialises the Guzzle client with default middleware
     *
     * @return Client
     */
    public static function create(): Client
    {
        return self::createWithMiddleware(self::getDefaultHandlerStack());
    }

    /**
     * Initialises the Guzzle client with provided middleware
     *
     * @param HandlerStack $handlerStack
     * @return Client
     */
    public static function createWithMiddleware(HandlerStack $handlerStack): Client
    {
        return new Client(['handler' => $handlerStack]);
    }

    /**
     * Initialises the client with Guzzle request options (https://docs.guzzlephp.org/en/stable/request-options.html)
     *
     * @param array<string, mixed> $guzzleConfig
     * @return Client
     */
    public static function createWithConfig(array $guzzleConfig): Client
    {
        return new Client(array_merge(['handler' => self::getDefaultHandlerStack()], $guzzleConfig));
    }

    /**
     * Returns default set of middleware to use for Guzzle clients
     *
     * @return HandlerStack
     */
    public static function getDefaultHandlerStack(): HandlerStack
    {
        $handlerStack = new HandlerStack(Utils::chooseHandler());
        $handlerStack->push(KiotaMiddleware::parameterNamesDecoding(), ParametersNameDecodingHandler::HANDLER_NAME);
        $handlerStack->push(GuzzleMiddleware::redirect(), 'kiotaRedirectHandler');
        $handlerStack->push(KiotaMiddleware::userAgent(), UserAgentHandler::HANDLER_NAME);
        $handlerStack->push(KiotaMiddleware::retry(), RetryHandler::HANDLER_NAME);
        $handlerStack->push(KiotaMiddleware::headersInspection(), HeadersInspectionHandler::HANDLER_NAME);
        return $handlerStack;
    }
}
