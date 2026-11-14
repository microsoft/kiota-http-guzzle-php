<?php

namespace Microsoft\Kiota\Http\Test\Middleware;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Response;
use Microsoft\Kiota\Http\Middleware\KiotaMiddleware;
use Microsoft\Kiota\Http\Middleware\Options\CompressionOption;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class CompressionHandlerTest extends TestCase
{
    public function testGzipCompression()
    {
        $initialRequestBody = "body";
        $mockResponse = [
            function (RequestInterface $request, array $options) use ($initialRequestBody) {
                $decompressedBody = gzdecode($request->getBody()->getContents());
                if ($decompressedBody === $initialRequestBody
                    && $request->hasHeader('Content-Encoding')
                    && $request->getHeaderLine('Content-Encoding') === 'gzip') {
                    return new Response(200);
                }
                throw new \RuntimeException("Decompressed body doesn't match initial request body");
            }
        ];

        $response = $this->executeMockRequest($mockResponse, null, ['body' => $initialRequestBody]); // gzip is default
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testDeflateCompression()
    {
        $initialRequestBody = "body";
        $mockResponse = [
            function (RequestInterface $request, array $options) use ($initialRequestBody) {
                $decompressedBody = gzinflate($request->getBody()->getContents());
                if ($decompressedBody === $initialRequestBody
                    && $request->hasHeader('Content-Encoding')
                    && $request->getHeaderLine('Content-Encoding') === 'deflate') {
                    return new Response(200);
                }
                throw new \RuntimeException("Decompressed body doesn't match initial request body");
            }
        ];

        $response = $this->executeMockRequest($mockResponse, new CompressionOption([CompressionOption::deflate()]), ['body' => $initialRequestBody]); // gzip is default
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testAtMostOneRetry()
    {
        $mockResponse = [
            new Response(415),
            new Response(200)
        ];
        $response = $this->executeMockRequest($mockResponse, null, ['body' => "Request body"]);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testUncompressedBodyPreservedOn415Retry(): void
    {
        $payload = "CRITICAL_PAYLOAD_TO_PRESERVE";
        $receivedBodies = [];
        $receivedEncodings = [];

        $mockResponse = [
            function (RequestInterface $request) use (&$receivedBodies, &$receivedEncodings) {
                $receivedEncodings[] = $request->getHeaderLine('Content-Encoding');
                $receivedBodies[] = $request->getBody()->getContents();
                return new Response(415);
            },
            function (RequestInterface $request) use (&$receivedBodies, &$receivedEncodings) {
                $receivedEncodings[] = $request->getHeaderLine('Content-Encoding');
                $receivedBodies[] = $request->getBody()->getContents();
                return new Response(200);
            }
        ];

        $response = $this->executeMockRequest($mockResponse, null, ['body' => $payload]);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertCount(2, $receivedBodies);

        // Initial request was compressed with gzip
        $this->assertEquals('gzip', $receivedEncodings[0]);

        // Retry request must have uncompressed body preserved (not empty) and no Content-Encoding header
        $this->assertEmpty($receivedEncodings[1]);
        $this->assertEquals($payload, $receivedBodies[1]);
    }

    public function testBadResponseExceptionCausesAtMostOneRetry()
    {
        $mockResponse = [
            new Response(415),
            new Response(200)
        ];
        $response = $this->executeMockRequest($mockResponse, null, ['body' => "Request body", 'http_errors' => true]);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testUncompressedBodyPreservedOnBadResponseException415Retry(): void
    {
        $payload = "PAYLOAD_RECOVERED_AFTER_BAD_RESPONSE_EXCEPTION";
        $receivedBodies = [];
        $receivedEncodings = [];

        $mockResponse = [
            function (RequestInterface $request) use (&$receivedBodies, &$receivedEncodings) {
                $receivedEncodings[] = $request->getHeaderLine('Content-Encoding');
                $receivedBodies[] = $request->getBody()->getContents();
                return new Response(415);
            },
            function (RequestInterface $request) use (&$receivedBodies, &$receivedEncodings) {
                $receivedEncodings[] = $request->getHeaderLine('Content-Encoding');
                $receivedBodies[] = $request->getBody()->getContents();
                return new Response(200);
            }
        ];

        $response = $this->executeMockRequest($mockResponse, null, [
            'body' => $payload,
            'http_errors' => true
        ]);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertCount(2, $receivedBodies);
        $this->assertEquals('gzip', $receivedEncodings[0]);
        $this->assertEmpty($receivedEncodings[1]);
        $this->assertEquals($payload, $receivedBodies[1]);
    }

    public function testConcurrentRequestsDoNotContaminateEachOtherOnRetry(): void
    {
        $recordedRequests = [];
        $mockHandler = new MockHandler([
            function (RequestInterface $request) use (&$recordedRequests) {
                // Request A (first attempt) -> 415
                $recordedRequests[] = [
                    'uri' => (string) $request->getUri(),
                    'encoding' => $request->getHeaderLine('Content-Encoding'),
                    'body' => $request->getBody()->getContents()
                ];
                return new Response(415);
            },
            function (RequestInterface $request) use (&$recordedRequests) {
                // Request B (first attempt) -> 200
                $recordedRequests[] = [
                    'uri' => (string) $request->getUri(),
                    'encoding' => $request->getHeaderLine('Content-Encoding'),
                    'body' => $request->getBody()->getContents()
                ];
                return new Response(200);
            },
            function (RequestInterface $request) use (&$recordedRequests) {
                // Request A (retry attempt) -> 200
                $recordedRequests[] = [
                    'uri' => (string) $request->getUri(),
                    'encoding' => $request->getHeaderLine('Content-Encoding'),
                    'body' => $request->getBody()->getContents()
                ];
                return new Response(200);
            }
        ]);

        $handlerStack = new HandlerStack($mockHandler);
        $handlerStack->push(KiotaMiddleware::compression());
        $client = new Client(['handler' => $handlerStack, 'http_errors' => false]);

        $promiseA = $client->postAsync('https://example.com/endpoint-a', ['body' => 'PAYLOAD_A']);
        $promiseB = $client->postAsync('https://example.com/endpoint-b', ['body' => 'PAYLOAD_B']);

        $results = Utils::settle([$promiseA, $promiseB])->wait();

        $this->assertEquals(200, $results[0]['value']->getStatusCode());
        $this->assertEquals(200, $results[1]['value']->getStatusCode());

        $this->assertCount(3, $recordedRequests);

        // First was Request A to endpoint-a
        $this->assertEquals('https://example.com/endpoint-a', $recordedRequests[0]['uri']);
        $this->assertEquals('gzip', $recordedRequests[0]['encoding']);

        // Second was Request B to endpoint-b
        $this->assertEquals('https://example.com/endpoint-b', $recordedRequests[1]['uri']);
        $this->assertEquals('gzip', $recordedRequests[1]['encoding']);

        // Third was Request A retrying uncompressed to endpoint-a with PAYLOAD_A (no leak of endpoint-b or PAYLOAD_B)
        $this->assertEquals('https://example.com/endpoint-a', $recordedRequests[2]['uri']);
        $this->assertEmpty($recordedRequests[2]['encoding']);
        $this->assertEquals('PAYLOAD_A', $recordedRequests[2]['body']);
    }

    public function testMultipleCompressionCallbacks()
    {
        $body = "payload";
        $compressionOptions = [CompressionOption::gzip(), CompressionOption::deflate()];
        $mockResponse = [
            function (RequestInterface $request, array $options) use ($body) {
                $decompressedBody = gzdecode(gzinflate($request->getBody()->getContents()));
                if ($decompressedBody === $body
                    && $request->hasHeader('Content-Encoding')
                    && $request->getHeaderLine('Content-Encoding') === 'gzip, deflate') {
                    return new Response(200);
                }
                throw new \RuntimeException("Decompressed body doesn't match initial request body");
            }
        ];
        $response = $this->executeMockRequest($mockResponse, new CompressionOption($compressionOptions), ['body' => $body]);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testPerRequestOptionDoesNotLeakToSubsequentRequests(): void
    {
        $defaultOption = new CompressionOption([CompressionOption::gzip()]);
        $customOption = new CompressionOption([CompressionOption::deflate()]);

        $recordedEncodings = [];
        $mockHandler = new MockHandler([
            function (RequestInterface $request) use (&$recordedEncodings) {
                $recordedEncodings[] = $request->getHeaderLine('Content-Encoding');
                return new Response(200);
            },
            function (RequestInterface $request) use (&$recordedEncodings) {
                $recordedEncodings[] = $request->getHeaderLine('Content-Encoding');
                return new Response(200);
            }
        ]);
        $handlerStack = new HandlerStack($mockHandler);
        $handlerStack->push(KiotaMiddleware::compression($defaultOption));
        $client = new Client(['handler' => $handlerStack, 'http_errors' => false]);

        // Request 1 with custom per-request option (deflate)
        $client->get('/', [
            'body' => 'payload',
            CompressionOption::class => $customOption
        ]);

        // Request 2 without options should use default handler option (gzip)
        $client->get('/', [
            'body' => 'payload'
        ]);

        $this->assertCount(2, $recordedEncodings);
        $this->assertEquals('deflate', $recordedEncodings[0]);
        $this->assertEquals('gzip', $recordedEncodings[1]);
    }

    public function testRetryOn415WithPerRequestOptionDoesNotLeakToSubsequentRequests(): void
    {
        $defaultOption = new CompressionOption([CompressionOption::gzip()]);
        $customOption = new CompressionOption([CompressionOption::deflate()]);

        $recordedEncodings = [];
        $mockHandler = new MockHandler([
            new Response(415),
            function (RequestInterface $request) use (&$recordedEncodings) {
                $recordedEncodings[] = $request->getHeaderLine('Content-Encoding');
                return new Response(200);
            },
            function (RequestInterface $request) use (&$recordedEncodings) {
                $recordedEncodings[] = $request->getHeaderLine('Content-Encoding');
                return new Response(200);
            }
        ]);
        $handlerStack = new HandlerStack($mockHandler);
        $handlerStack->push(KiotaMiddleware::compression($defaultOption));
        $client = new Client(['handler' => $handlerStack, 'http_errors' => false]);

        $res1 = $client->get('/', [
            'body' => 'payload',
            CompressionOption::class => $customOption
        ]);
        $this->assertEquals(200, $res1->getStatusCode());

        $res2 = $client->get('/', [
            'body' => 'payload'
        ]);
        $this->assertEquals(200, $res2->getStatusCode());

        $this->assertCount(2, $recordedEncodings);
        $this->assertEmpty($recordedEncodings[0]);
        $this->assertEquals('gzip', $recordedEncodings[1]);
    }

    private function executeMockRequest(array $mockResponses, ?CompressionOption $compressionOption = null, ?array $requestOptions = [])
    {
        $mockHandler = new MockHandler($mockResponses);
        $handlerStack = new HandlerStack($mockHandler);
        $handlerStack->push(KiotaMiddleware::compression($compressionOption));

        $guzzleClient = new Client(['handler' => $handlerStack, 'http_errors' => false]);
        return $guzzleClient->get("/", $requestOptions);
    }
}
