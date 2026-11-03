<?php

namespace Microsoft\Kiota\Http\Test\Middleware;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Microsoft\Kiota\Http\Middleware\ChaosHandler;
use Microsoft\Kiota\Http\Middleware\KiotaMiddleware;
use Microsoft\Kiota\Http\Middleware\Options\ChaosOption;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class ChaosHandlerTest extends TestCase
{
    public function testRandomResponseIsSelectedFromChaosOptions()
    {
        $chaosOptions = new ChaosOption([
            new Response(404),
            function (RequestInterface $request, array $options) {
                return new Response(500);
            }
        ], 100);

        $chaosClient = $this->getChaosClient($chaosOptions);
        $chaosResponse = $chaosClient->get("/");
        $this->assertTrue($chaosResponse->getStatusCode() > 200); // mock handler returns 200s only
    }

    public function testDefaultResponseIsSelectedIfNoChaosResponses()
    {
        $chaosOptions = new ChaosOption([], 100);
        $chaosClient = $this->getChaosClient($chaosOptions);
        $response = $chaosClient->get("/");
        $this->assertInstanceOf(Response::class, $response);
    }

    public function testRequestLevelConfigOverridesClientLevelConfig()
    {
        $requestChaosOptions = new ChaosOption([
            new Response(300),
            function (RequestInterface $request, array $options) {
                return new Response(300);
            }
        ], 100);
        $requestOption = [
            ChaosOption::class => $requestChaosOptions
        ];
        $chaosClient = $this->getChaosClient(new ChaosOption());
        $chaosResponse = $chaosClient->get("/", $requestOption);
        $this->assertTrue($chaosResponse->getStatusCode() == 300);
    }

    public function testPerRequestOptionDoesNotLeakToSubsequentRequests(): void
    {
        $defaultOption = new ChaosOption([], 0);
        $customOption = new ChaosOption([new Response(500)], 100);

        $chaosClient = $this->getChaosClient($defaultOption);

        // Request 1 with custom per-request option (100% chaos -> returns 500)
        $response1 = $chaosClient->get('/', [ChaosOption::class => $customOption]);
        $this->assertEquals(500, $response1->getStatusCode());

        // Request 2 without options should use default handler option (0% chaos -> returns 200 from mock handler)
        $response2 = $chaosClient->get('/');
        $this->assertEquals(200, $response2->getStatusCode());
    }

    private function getChaosClient(ChaosOption $chaosOption): Client
    {
        $mockHandler = new MockHandler([
            new Response(200),
            new Response(200),
            new Response(200),
            new Response(200),
            new Response(200)
        ]);
        $handlerStack = new HandlerStack($mockHandler);
        $handlerStack->push(KiotaMiddleware::chaos($chaosOption));
        return new Client(['handler' => $handlerStack, 'http_errors' => false]);
    }


}
