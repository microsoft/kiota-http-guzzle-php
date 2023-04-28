<?php

namespace Microsoft\Kiota\Http\Test;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Microsoft\Kiota\Http\Constants;
use Microsoft\Kiota\Http\KiotaClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class KiotaClientFactoryTest extends TestCase
{
    public function testCreateReturnsClient()
    {
        $this->assertInstanceOf(\GuzzleHttp\Client::class, KiotaClientFactory::create());
    }

    public function testCreateWithMiddleware()
    {
        $handlerStack = new HandlerStack();
        $this->assertInstanceOf(\GuzzleHttp\Client::class, KiotaClientFactory::createWithMiddleware($handlerStack));
    }

    public function testGetDefaultHandlerStack()
    {
        $this->assertInstanceOf(HandlerStack::class, KiotaClientFactory::getDefaultHandlerStack());
    }

    public function testMiddlewareProcessing()
    {
        $guzzleVersion = ClientInterface::MAJOR_VERSION;
        $kiotaVersion = Constants::KIOTA_HTTP_CLIENT_VERSION;
        $userAgentHeaderValue = "GuzzleHttp/$guzzleVersion kiota-php/$kiotaVersion";
        $mockResponses = [
            function (RequestInterface $request) use ($userAgentHeaderValue) {
                // test parameter name decoding
                $this->assertEquals('https://graph.microsoft.com/users?$top=5', (string) $request->getUri());
                $this->assertTrue($request->hasHeader('User-Agent'));
                $this->assertEquals($userAgentHeaderValue, $request->getHeaderLine('User-Agent'));
                // trigger retry
                return new Response(429, ['Retry-After' => '1']);
            },
            function (RequestInterface $retriedRequest) use ($userAgentHeaderValue) {
                $this->assertEquals('https://graph.microsoft.com/users?$top=5', (string) $retriedRequest->getUri());
                $this->assertTrue($retriedRequest->hasHeader('User-Agent'));
                $this->assertEquals($userAgentHeaderValue, $retriedRequest->getHeaderLine('User-Agent'));
                $this->assertTrue($retriedRequest->hasHeader('Retry-Attempt'));
                $this->assertEquals('1', $retriedRequest->getHeaderLine('Retry-Attempt'));
                // trigger redirect
                return new Response(302, ['Location' => 'https://graph.microsoft.com/users?%24top=5']);
            },
            function (RequestInterface $request) use ($userAgentHeaderValue) {
                // test no parameter name decoding. Redirect happens as is
                $this->assertEquals('https://graph.microsoft.com/users?%24top=5', (string) $request->getUri());
                $this->assertTrue($request->hasHeader('User-Agent'));
                $this->assertEquals($userAgentHeaderValue, $request->getHeaderLine('User-Agent'));
                return new Response(200);
            }
        ];
        $middlewareStack = KiotaClientFactory::getDefaultHandlerStack();
        $middlewareStack->setHandler(new MockHandler($mockResponses));
        $mockClient = new Client(['handler' => $middlewareStack]);
        $mockClient->get('https://graph.microsoft.com/users?%24top=5');
    }
}
