<?php

namespace Microsoft\Kiota\Http\Test\Middleware;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Microsoft\Kiota\Http\Middleware\KiotaMiddleware;
use Microsoft\Kiota\Http\Middleware\Options\RetryOption;
use Microsoft\Kiota\Http\Middleware\RetryHandler;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class RetryHandlerTest extends TestCase
{
    private RetryOption $testRetryOption;

    public function setUp(): void
    {
        $this->testRetryOption = new RetryOption(3, 1);
    }

    public function testRetryStatusCodes()
    {
        $requestIdHeader = "Request-Id";
        $requestId = "123";
        $requestRetried = false;
        $responseCallback = function (RequestInterface $request, array $options) use ($requestIdHeader, $requestId, &$requestRetried) {
            if ($request->hasHeader($requestIdHeader)
                && $request->getHeader($requestIdHeader)[0] === $requestId) {
                $requestRetried = true;
            }
            return new Response(200);
        } ;

        $mockResponses = [
            new Response(429),
            new Response(503),
            new Response(504),
            $responseCallback
        ];
        $this->executeMockRequestWithRetryHandler($mockResponses, null, ['headers' => [$requestIdHeader => $requestId]]);
        $this->assertTrue($requestRetried);
    }

    public function testDelayUsingRetryOption()
    {
        $delaySecs = 1;
        $this->testRetryOption->setDelay($delaySecs);
        $mockResponses = [
            new Response(429),
            function (RequestInterface $request, array $options) use ($delaySecs) {
                if (array_key_exists('delay', $options) && $options['delay'] === $delaySecs * 1000) {
                    return new Response(200);
                }
                return new \RuntimeException("Expected delay is not set");
            }
        ];
        $response = $this->executeMockRequestWithRetryHandler($mockResponses, $this->testRetryOption);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testDelayUsingRetryAfterHeaderSecondsValue()
    {
        $retryAfterSecs = 2;
        $mockResponses = [
            new Response(429, ['Retry-After' => $retryAfterSecs]),
            function (RequestInterface $request, array $options) {
                if (array_key_exists('delay', $options) && $options['delay'] > $this->testRetryOption->getDelay() * 1000) {
                    return new Response(200);
                }
                return new \RuntimeException("Expected delay is not set");
            }
        ];
        $response = $this->executeMockRequestWithRetryHandler($mockResponses, $this->testRetryOption);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testRetryHandlerDelaysUsingRetryAfterHeaderDateValue()
    {
        $retryAfterSecs = (new \DateTime())->getTimestamp() + 2;
        $retryAfterDate = new \DateTime("@$retryAfterSecs");
        $mockResponses = [
            new Response(429, ['Retry-After' => $retryAfterDate->format(\DateTimeInterface::RFC7231)]),
            function (RequestInterface $request, array $options) {
                if (array_key_exists('delay', $options) && $options['delay'] > $this->testRetryOption->getDelay() * 1000) {
                    return new Response(200);
                }
                return new \RuntimeException("Expected delay is not set");
            }
        ];
        $response = $this->executeMockRequestWithRetryHandler($mockResponses, $this->testRetryOption);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testRetryHandlerDelaysUsingExponentialBackoff()
    {
        $mockResponses = [
            new Response(429),
            new Response(429),
            function (RequestInterface $request, array $options) {
                if (array_key_exists('delay', $options)
                    && $options['delay'] === RetryHandler::exponentialDelay(2, $this->testRetryOption->getDelay()) * 1000) {
                    return new Response(200);
                }
                return new \RuntimeException("Expected delay is not set");
            }
        ];
        $response = $this->executeMockRequestWithRetryHandler($mockResponses, $this->testRetryOption);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testRetryAttemptHeaderIsAccurate()
    {
        $mockResponses = [
            new Response(429),
            function (RequestInterface $request, array $options) {
                if ($request->hasHeader('Retry-Attempt')
                    && $request->getHeader('Retry-Attempt')[0] == 1) {
                    return new Response(429);
                }
                return new \RuntimeException("Wrong retry-attempt value found");
            },
            function (RequestInterface $request, array $options) {
                if ($request->hasHeader('Retry-Attempt')
                    && $request->getHeader('Retry-Attempt')[0] == 2) {
                    return new Response(200);
                }
                return new \RuntimeException("Wrong retry-attempt value found");
            }
        ];

        $response = $this->executeMockRequestWithRetryHandler($mockResponses);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testRequestPayloadNotManipulatedDuringRetry()
    {
        $initialRequestBody = [
            'userId' => 1
        ];
        $mockResponse = [
            new Response(429),
            function (RequestInterface $request, array $options) use ($initialRequestBody) {
                $retryRequestBody = json_decode($request->getBody()->getContents(), true);
                if ($initialRequestBody == $retryRequestBody) {
                    return new Response(200);
                }
                return new \RuntimeException("Retry Request body has been manipulated");
            }
        ];
        $response = $this->executeMockRequestWithRetryHandler($mockResponse, $this->testRetryOption, ['body' => json_encode($initialRequestBody)]);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testBadResponseExceptionsCauseRetry()
    {
        $mockResponses = [
            new BadResponseException("429 returned", new Request("GET", "/"), new Response(429)),
            new Response(200)
        ];
        $response = $this->executeMockRequestWithRetryHandler($mockResponses, $this->testRetryOption, ['http_errors' => true]);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testNonBadResponseExceptionsDontRetry()
    {
        $this->expectException(NetworkExceptionInterface::class);
        $mockResponses = [
            $this->createMock(NetworkExceptionInterface::class)
        ];
        $response = $this->executeMockRequestWithRetryHandler($mockResponses);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testShouldRetryCallbackIsConsidered()
    {
        $shouldRetry = function (int $delay, int $retries, ResponseInterface $response) {
            return false;
        };
        $this->testRetryOption->setShouldRetry($shouldRetry);
        $mockResponses = [
            new Response(429)
        ];
        $response = $this->executeMockRequestWithRetryHandler($mockResponses, $this->testRetryOption);
        $this->assertEquals(429, $response->getStatusCode());
    }

    public function testRetriesTimeLimitIsNotExceeded()
    {
        $retriesTimeLimit = new \DateInterval("PT20S");
        $this->testRetryOption->setDelay(100)->setRetriesTimeLimit($retriesTimeLimit);
        $mockResponses = [
            new Response(429)
        ];
        $response = $this->executeMockRequestWithRetryHandler($mockResponses, $this->testRetryOption);
        $this->assertEquals(429, $response->getStatusCode());
    }

    public function testMaxRetriesNotExceeded()
    {
        $this->testRetryOption->setMaxRetries(1);
        $mockResponses = [
            new Response(429),
            new Response(429),
            new Response(429)
        ];
        $response = $this->executeMockRequestWithRetryHandler($mockResponses, $this->testRetryOption);
        $this->assertEquals(429, $response->getStatusCode());
    }


    private function executeMockRequestWithRetryHandler(array $mockResponses, ?RetryOption $retryOption = null, ?array $requestOptions = []) : ResponseInterface
    {
        $mockHandler = new MockHandler($mockResponses);
        $handlerStack = new HandlerStack($mockHandler);
        $retryOption = $retryOption ?: $this->testRetryOption;
        $handlerStack->push(KiotaMiddleware::retry($retryOption));

        $guzzleClient = new Client(['handler' => $handlerStack, 'http_errors' => false]);
        return $guzzleClient->get("/", $requestOptions);
    }
}
