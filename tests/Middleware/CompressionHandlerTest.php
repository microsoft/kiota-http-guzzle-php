<?php

namespace Microsoft\Kiota\Http\Test\Middleware;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
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

    public function testBadResponseExceptionCausesAtMostOneRetry()
    {
        $mockResponse = [
            new Response(415),
            new Response(200)
        ];
        $response = $this->executeMockRequest($mockResponse, null, ['body' => "Request body", 'http_errors' => true]);
        $this->assertEquals(200, $response->getStatusCode());
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
