<?php

namespace Microsoft\Kiota\Http\Test\Middleware;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Microsoft\Kiota\Abstractions\RequestHeaders;
use Microsoft\Kiota\Http\Middleware\KiotaMiddleware;
use Microsoft\Kiota\Http\Middleware\Options\HeadersInspectionHandlerOption;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class HeadersInspectionHandlerTest extends TestCase
{
    private static $requestHeaders = [
        'accept' => ['application/json'],
        'content-type' => ['application/json'],
        'sdkversion' => ['1.0.0'],
        'user-agent' => ['kiota-http/1.0.0']
    ];

    private static $responseHeaders = [
        'content-type' => ['application/json']
    ];

    public function testHeadersInspectionDisabledByDefault(): void
    {
        $option = new HeadersInspectionHandlerOption();
        $mockResponse = [
            new Response(200, self::$responseHeaders)
        ];
        $response = $this->executeMockRequest($mockResponse, $option);
        $this->assertInstanceOf(RequestHeaders::class, $option->getRequestHeaders());
        $this->assertInstanceOf(RequestHeaders::class, $option->getResponseHeaders());
        $this->assertEmpty($option->getRequestHeaders()->getAll());
        $this->assertEmpty($option->getResponseHeaders()->getAll());
    }

    public function testRequestHeadersAdded(): void
    {
        $option = new HeadersInspectionHandlerOption(false, true);
        $mockResponse = [
            new Response(200, self::$responseHeaders)
        ];
        $response = $this->executeMockRequest($mockResponse, $option);
        $this->assertInstanceOf(RequestHeaders::class, $option->getRequestHeaders());
        $this->assertInstanceOf(RequestHeaders::class, $option->getResponseHeaders());
        $this->assertEquals(self::$requestHeaders, $option->getRequestHeaders()->getAll());
        $this->assertEmpty($option->getResponseHeaders()->getAll());
    }

    public function testResponseHeadersAdded(): void
    {
        $option = new HeadersInspectionHandlerOption(true, true);
        $mockResponse = [
            new Response(200, self::$responseHeaders)
        ];
        $response = $this->executeMockRequest($mockResponse, $option);
        $this->assertInstanceOf(RequestHeaders::class, $option->getRequestHeaders());
        $this->assertInstanceOf(RequestHeaders::class, $option->getResponseHeaders());
        $this->assertEquals(self::$requestHeaders, $option->getRequestHeaders()->getAll());
        $this->assertEquals(self::$responseHeaders, $option->getResponseHeaders()->getAll());
    }

    public function testPerRequestOptionDoesNotLeakToSubsequentRequests(): void
    {
        $handlerOption = new HeadersInspectionHandlerOption(false, false);
        $requestOption = new HeadersInspectionHandlerOption(true, true);
        $secondRequestHeaders = [
            'x-request-two' => ['second-request']
        ];
        $secondResponseHeaders = [
            'x-response-two' => ['second-response']
        ];
        $mockResponses = [
            new Response(200, self::$responseHeaders),
            new Response(200, $secondResponseHeaders)
        ];

        $mockHandler = new MockHandler($mockResponses);
        $handlerStack = new HandlerStack($mockHandler);
        $handlerStack->push(KiotaMiddleware::headersInspection($handlerOption));
        $client = new Client(['handler' => $handlerStack, 'http_errors' => false]);

        // Request 1 with per-request option enabled
        $client->request('GET', '/', [
            'headers' => self::$requestHeaders,
            HeadersInspectionHandlerOption::class => $requestOption
        ]);
        $this->assertEquals(self::$requestHeaders, $requestOption->getRequestHeaders()->getAll());
        $this->assertEquals(self::$responseHeaders, $requestOption->getResponseHeaders()->getAll());

        // Default handler option should remain unmutated (disabled)
        $this->assertEmpty($handlerOption->getRequestHeaders()->getAll());
        $this->assertEmpty($handlerOption->getResponseHeaders()->getAll());

        // Capture request 1's header objects before request 2
        $initialRequestHeaders = $requestOption->getRequestHeaders();
        $initialResponseHeaders = $requestOption->getResponseHeaders();

        // Request 2 without options should use default handler option without leaking request 1's options
        $client->request('GET', '/', [
            'headers' => $secondRequestHeaders
        ]);

        // Default handler option should remain unmutated (disabled)
        $this->assertEmpty($handlerOption->getRequestHeaders()->getAll());
        $this->assertEmpty($handlerOption->getResponseHeaders()->getAll());

        // Request 1's header objects must remain the same instances and not be overwritten by request 2
        $this->assertSame($initialRequestHeaders, $requestOption->getRequestHeaders());
        $this->assertSame($initialResponseHeaders, $requestOption->getResponseHeaders());
        $this->assertEquals(self::$requestHeaders, $requestOption->getRequestHeaders()->getAll());
        $this->assertEquals(self::$responseHeaders, $requestOption->getResponseHeaders()->getAll());
    }

    private function executeMockRequest(array $mockResponses, ?HeadersInspectionHandlerOption $headersInspectionOption = null, ?array $requestOptions = []): ResponseInterface
    {
        $mockHandler = new MockHandler($mockResponses);
        $handlerStack = new HandlerStack($mockHandler);
        $handlerStack->push(KiotaMiddleware::headersInspection($headersInspectionOption));

        $guzzleClient = new Client(['handler' => $handlerStack, 'http_errors' => false]);
        $options = array_merge($requestOptions, [
            'headers' => self::$requestHeaders
        ]);
        return $guzzleClient->request('GET', '/', $options);
    }
}
