<?php

namespace Microsoft\Kiota\Http\Test\Middleware;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Microsoft\Kiota\Http\Middleware\KiotaMiddleware;
use Microsoft\Kiota\Http\Middleware\Options\TelemetryOption;
use Microsoft\Kiota\Http\Middleware\TelemetryHandler;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class TelemetryHandlerTest extends TestCase
{
    public function testTelemetry()
    {
        $telemetryConfigurator = function (RequestInterface $request) {
            return $request->withHeader('SdkVersion', '1.0');
        };
        $telemetryOption = new TelemetryOption($telemetryConfigurator);
        $mockResponse = [
            function (RequestInterface $request, array $options) {
                if ($request->hasHeader('SdkVersion') && $request->getHeaderLine('SdkVersion') === '1.0') {
                    return new Response(200);
                }
                throw new \RuntimeException("Telemetry header not set");
            }
        ];
        $response = $this->executeMockRequest($mockResponse, $telemetryOption);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testTelemetryWithNullCallback()
    {
        $telemetryOption = new TelemetryOption(null);
        $mockResponse = [new Response(200)];
        $response = $this->executeMockRequest($mockResponse, $telemetryOption);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testRequestOptionsDoNotLeakAcrossRequests(): void
    {
        $defaultConfigurator = function (RequestInterface $request) {
            return $request->withHeader('SdkVersion', '1.0.0');
        };
        $defaultOption = new TelemetryOption($defaultConfigurator);

        $customConfigurator = function (RequestInterface $request) {
            return $request->withHeader('SdkVersion', '2.0.0');
        };
        $customOption = new TelemetryOption($customConfigurator);

        $capturedHeaders = [];
        $mockHandler = new MockHandler([
            function (RequestInterface $request) use (&$capturedHeaders) {
                $capturedHeaders[] = $request->getHeaderLine('SdkVersion');
                return new Response(200);
            },
            function (RequestInterface $request) use (&$capturedHeaders) {
                $capturedHeaders[] = $request->getHeaderLine('SdkVersion');
                return new Response(200);
            }
        ]);

        $handlerStack = new HandlerStack($mockHandler);
        $handlerStack->push(KiotaMiddleware::telemetry($defaultOption));
        $client = new Client(['handler' => $handlerStack]);

        // Request 1: custom option supplied at request level
        $client->get('/', [TelemetryOption::class => $customOption]);

        // Request 2: executed without request-level options should use defaultOption
        $client->get('/');

        $this->assertCount(2, $capturedHeaders);
        $this->assertEquals('2.0.0', $capturedHeaders[0]);
        $this->assertEquals('1.0.0', $capturedHeaders[1]);
    }

    public function testRequestOptionsDoNotLeakWhenNoDefaultOptionProvided(): void
    {
        $customConfigurator = function (RequestInterface $request) {
            return $request->withHeader('Custom-Telemetry', 'enabled');
        };
        $customOption = new TelemetryOption($customConfigurator);

        $capturedHeaders = [];
        $mockHandler = new MockHandler([
            function (RequestInterface $request) use (&$capturedHeaders) {
                $capturedHeaders[] = $request->getHeaderLine('Custom-Telemetry');
                return new Response(200);
            },
            function (RequestInterface $request) use (&$capturedHeaders) {
                $capturedHeaders[] = $request->getHeaderLine('Custom-Telemetry');
                return new Response(200);
            }
        ]);

        $handlerStack = new HandlerStack($mockHandler);
        $handlerStack->push(KiotaMiddleware::telemetry(null));
        $client = new Client(['handler' => $handlerStack]);

        // Request 1: custom option supplied at request level
        $client->get('/', [TelemetryOption::class => $customOption]);

        // Request 2: executed without request-level options should not have header set
        $client->get('/');

        $this->assertCount(2, $capturedHeaders);
        $this->assertEquals('enabled', $capturedHeaders[0]);
        $this->assertEquals('', $capturedHeaders[1]);
    }

    public function testHandlerGetters(): void
    {
        $nextHandler = function (RequestInterface $request, array $options) {
            return new \GuzzleHttp\Promise\FulfilledPromise(new Response(200));
        };
        $option = new TelemetryOption(null);
        $handler = new TelemetryHandler($nextHandler, $option);

        $this->assertSame($nextHandler, $handler->getNextHandler());
        $this->assertSame($option, $handler->getTelemetryOption());
        $this->assertEquals('kiotaTelemetryHandler', TelemetryHandler::HANDLER_NAME);
    }

    private function executeMockRequest(array $mockResponses, ?TelemetryOption $telemetryOption = null, ?array $requestOptions = [])
    {
        $mockHandler = new MockHandler($mockResponses);
        $handlerStack = new HandlerStack($mockHandler);
        $handlerStack->push(KiotaMiddleware::telemetry($telemetryOption));

        $guzzleClient = new Client(['handler' => $handlerStack, 'http_errors' => false]);
        return $guzzleClient->get("/", $requestOptions);
    }
}
