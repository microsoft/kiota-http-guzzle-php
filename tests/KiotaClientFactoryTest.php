<?php

namespace Microsoft\Kiota\Http\Test;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Microsoft\Kiota\Http\Constants;
use Microsoft\Kiota\Http\KiotaClientFactory;
use Microsoft\Kiota\Http\Middleware\Options\RetryOption;
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

    /**
     * @dataProvider queryRedirectStatusCodes
     */
    public function testQueryRequestRedirect(int $statusCode, string $expectedMethod): void
    {
        $uri = 'https://graph.microsoft.com/users';
        $redirectUri = 'https://graph.microsoft.com/redirected-users';
        $body = '{"displayName":"Ada"}';
        $mockHandler = new MockHandler([
            function (RequestInterface $request) use ($statusCode, $uri, $redirectUri, $body) {
                $this->assertSame('QUERY', $request->getMethod());
                $this->assertSame($uri, (string) $request->getUri());
                // Consume the body to verify it is rewound before resending.
                $this->assertSame($body, $request->getBody()->getContents());
                return new Response($statusCode, ['Location' => $redirectUri]);
            },
            function (RequestInterface $request) use ($expectedMethod, $redirectUri, $body) {
                $this->assertSame($expectedMethod, $request->getMethod());
                $this->assertSame($redirectUri, (string) $request->getUri());
                $this->assertSame($expectedMethod === 'GET' ? '' : $body, $request->getBody()->getContents());
                return new Response(200);
            }
        ]);
        $middlewareStack = KiotaClientFactory::getDefaultHandlerStack();
        $middlewareStack->setHandler($mockHandler);
        $client = KiotaClientFactory::createWithMiddleware($middlewareStack);

        $response = $client->request('QUERY', $uri, ['body' => $body]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(0, $mockHandler);
    }

    public static function queryRedirectStatusCodes(): array
    {
        return [
            '301 redirect' => [301, 'QUERY'],
            '302 redirect' => [302, 'QUERY'],
            '303 redirect to GET' => [303, 'GET'],
            '307 redirect' => [307, 'QUERY'],
            '308 redirect' => [308, 'QUERY']
        ];
    }

    /**
     * @dataProvider queryRetryStatusCodes
     */
    public function testQueryRequestRetry(int $statusCode): void
    {
        $uri = 'https://graph.microsoft.com/users';
        $body = '{"displayName":"Ada"}';
        $mockHandler = new MockHandler([
            function (RequestInterface $request) use ($statusCode, $uri, $body) {
                $this->assertSame('QUERY', $request->getMethod());
                $this->assertSame($uri, (string) $request->getUri());
                // Consume the body to verify it is rewound before retrying.
                $this->assertSame($body, $request->getBody()->getContents());
                return new Response($statusCode);
            },
            function (RequestInterface $request) use ($uri, $body) {
                $this->assertSame('QUERY', $request->getMethod());
                $this->assertSame($uri, (string) $request->getUri());
                $this->assertSame($body, $request->getBody()->getContents());
                $this->assertSame('1', $request->getHeaderLine('Retry-Attempt'));
                return new Response(200);
            }
        ]);
        $middlewareStack = KiotaClientFactory::getDefaultHandlerStack();
        $middlewareStack->setHandler($mockHandler);
        $client = KiotaClientFactory::createWithMiddleware($middlewareStack);

        $response = $client->request('QUERY', $uri, [
            'body' => $body,
            RetryOption::class => new RetryOption(1, 0)
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(0, $mockHandler);
    }

    public static function queryRetryStatusCodes(): array
    {
        return [
            '429 retry' => [429],
            '503 retry' => [503],
            '504 retry' => [504]
        ];
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
