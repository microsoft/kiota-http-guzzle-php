<?php

namespace Microsoft\Kiota\Http\Test\Middleware;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Microsoft\Kiota\Http\Middleware\KiotaMiddleware;
use Microsoft\Kiota\Http\Middleware\Options\UserAgentHandlerOption;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class UserAgentHandlerTest extends TestCase
{
    public function testUserAgentHandler()
    {
        $userAgentConfigurator = function (RequestInterface $request) {
            return $request->withHeader('User-Agent', 'kiota-php/0.4.2');
        };
        $userAgentHandlerOption = new UserAgentHandlerOption($userAgentConfigurator);
        $mockResponse = [
            function (RequestInterface $request) {
                $agentHeader = $request->getHeader('User-Agent');
                if ($agentHeader[0] ?? '' === 'kiota-php/0.4.2') {
                    return new Response(200);
                }
                return new Response(400);
            }
        ];

        $response = $this->executeMockRequest(
            $mockResponse,
            $userAgentHandlerOption,
            [UserAgentHandlerOption::class => new UserAgentHandlerOption($userAgentConfigurator)]
        );
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testUserAgentWithNullCallback()
    {
        $agentHandlerOption = new UserAgentHandlerOption(null);
        $agentHandlerOption->setProductName('kiota-php');
        $agentHandlerOption->setProductVersion('0.1.0');
        $req = null;
        $mockResponse = [function (RequestInterface $request) use (&$req) {
            $req = clone $request;
            return new Response(200);
        }];

        $response = $this->executeMockRequest($mockResponse, $agentHandlerOption);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertContains('GuzzleHttp/'.ClientInterface::MAJOR_VERSION.' kiota-php/0.1.0', $req->getHeader('User-Agent'));
    }

    private function executeMockRequest(
        array $mockResponses,
        ?UserAgentHandlerOption $agentHandlerOption = null,
        ?array $requestOptions = []
    )
    {
        $mockHandler = new MockHandler($mockResponses);
        $handlerStack = new HandlerStack($mockHandler);
        $handlerStack->push(KiotaMiddleware::userAgent($agentHandlerOption));

        $guzzleClient = new Client(['handler' => $handlerStack, 'http_errors' => false]);
        return $guzzleClient->get("/", $requestOptions);
    }
}
