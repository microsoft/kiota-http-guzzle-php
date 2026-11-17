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

    public function testConstructWithDefaultOption(): void
    {
        $nextHandler = function (RequestInterface $request, array $options) {
            return new \GuzzleHttp\Promise\FulfilledPromise(new Response(200));
        };
        $handler = new \Microsoft\Kiota\Http\Middleware\UserAgentHandler($nextHandler);
        $this->assertNotNull($handler);
        $this->assertSame($nextHandler, $handler->getNextHandler());
    }

    public function testRequestOptionsDoNotLeakAcrossRequests(): void
    {
        $defaultOption = new UserAgentHandlerOption();
        $defaultOption->setProductName('DefaultProduct');
        $defaultOption->setProductVersion('1.0.0');

        $capturedHeaders = [];
        $mockHandler = new MockHandler([
            function (RequestInterface $request) use (&$capturedHeaders) {
                $capturedHeaders[] = $request->getHeaderLine('User-Agent');
                return new Response(200);
            },
            function (RequestInterface $request) use (&$capturedHeaders) {
                $capturedHeaders[] = $request->getHeaderLine('User-Agent');
                return new Response(200);
            }
        ]);

        $handlerStack = new HandlerStack($mockHandler);
        $handlerStack->push(KiotaMiddleware::userAgent($defaultOption));
        $client = new Client(['handler' => $handlerStack]);

        // Request 1: custom option supplied at request level
        $customOption = new UserAgentHandlerOption();
        $customOption->setProductName('CustomProduct');
        $customOption->setProductVersion('2.0.0');

        $client->get('/', [UserAgentHandlerOption::class => $customOption]);

        // Request 2: executed without request-level options
        $client->get('/');

        $this->assertCount(2, $capturedHeaders);
        $this->assertStringContainsString('CustomProduct/2.0.0', $capturedHeaders[0]);
        $this->assertStringContainsString('DefaultProduct/1.0.0', $capturedHeaders[1]);
        $this->assertStringNotContainsString('CustomProduct/2.0.0', $capturedHeaders[1]);
    }

    public function testUserAgentNotDuplicatedWhenAlreadyPresent(): void
    {
        $option = new UserAgentHandlerOption();
        $option->setProductName('kiota-php');
        $option->setProductVersion('2.2.3');

        $capturedHeader = '';
        $mockHandler = new MockHandler([
            function (RequestInterface $request) use (&$capturedHeader) {
                $capturedHeader = $request->getHeaderLine('User-Agent');
                return new Response(200);
            }
        ]);

        $handlerStack = new HandlerStack($mockHandler);
        $handlerStack->push(KiotaMiddleware::userAgent($option));
        $client = new Client(['handler' => $handlerStack]);

        $client->get('/', [
            'headers' => ['User-Agent' => 'MyApp/1.0 kiota-php/2.2.3']
        ]);

        $this->assertEquals('MyApp/1.0 kiota-php/2.2.3', $capturedHeader);
    }

    public function testUserAgentAppendedWhenExistingHeaderContainsPrefixOrVersionSubstring(): void
    {
        $option = new UserAgentHandlerOption();
        $option->setProductName('kiota-php');
        $option->setProductVersion('2.2.3');

        $capturedHeaders = [];
        $mockHandler = new MockHandler([
            function (RequestInterface $request) use (&$capturedHeaders) {
                $capturedHeaders[] = $request->getHeaderLine('User-Agent');
                return new Response(200);
            },
            function (RequestInterface $request) use (&$capturedHeaders) {
                $capturedHeaders[] = $request->getHeaderLine('User-Agent');
                return new Response(200);
            }
        ]);

        $handlerStack = new HandlerStack($mockHandler);
        $handlerStack->push(KiotaMiddleware::userAgent($option));
        $client = new Client(['handler' => $handlerStack]);

        // Case 1: existing token is a version superset, e.g. kiota-php/2.2.30
        $client->get('/', [
            'headers' => ['User-Agent' => 'kiota-php/2.2.30']
        ]);

        // Case 2: existing token has a prefix, e.g. custom-kiota-php/2.2.3
        $client->get('/', [
            'headers' => ['User-Agent' => 'custom-kiota-php/2.2.3']
        ]);

        $this->assertCount(2, $capturedHeaders);
        $this->assertEquals('kiota-php/2.2.30 kiota-php/2.2.3', $capturedHeaders[0]);
        $this->assertEquals('custom-kiota-php/2.2.3 kiota-php/2.2.3', $capturedHeaders[1]);
    }

    public function testUserAgentDeduplicationHandlesWhitespaceVariations(): void
    {
        $option = new UserAgentHandlerOption();
        $option->setProductName('kiota-php');
        $option->setProductVersion('2.2.3');

        $capturedHeader = '';
        $mockHandler = new MockHandler([
            function (RequestInterface $request) use (&$capturedHeader) {
                $capturedHeader = $request->getHeaderLine('User-Agent');
                return new Response(200);
            }
        ]);

        $handlerStack = new HandlerStack($mockHandler);
        $handlerStack->push(KiotaMiddleware::userAgent($option));
        $client = new Client(['handler' => $handlerStack]);

        $client->get('/', [
            'headers' => ['User-Agent' => "MyApp/1.0   kiota-php/2.2.3\tOther/2.0"]
        ]);

        $this->assertEquals("MyApp/1.0   kiota-php/2.2.3\tOther/2.0", $capturedHeader);
    }
}
