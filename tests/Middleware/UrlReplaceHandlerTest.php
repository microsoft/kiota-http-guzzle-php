<?php

namespace Microsoft\Kiota\Http\Test\Middleware;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Microsoft\Kiota\Http\Middleware\KiotaMiddleware;
use Microsoft\Kiota\Http\Middleware\Options\ParametersDecodingOption;
use Microsoft\Kiota\Http\Middleware\Options\UrlReplaceOption;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class UrlReplaceHandlerTest extends TestCase
{
    private string $defaultUrl = 'https://graph.microsoft.com/users/user-id-to-replace/messages';
    private string $defaultReplacedUrl = 'https://graph.microsoft.com/me/messages';

    public function testDefaultWithNoReplacement(): void
    {
        $mockResponse = [
            function (RequestInterface $request, array $options) {
                $this->assertEquals($this->defaultReplacedUrl, strval($request->getUri()));
                return new Response(200);
            }
        ];
        $this->executeMockRequest($mockResponse);
    }

    public function testDisabledUriReplace(): void
    {
        $mockResponse = [
            function (RequestInterface $request, array $options) {
                $this->assertEquals($this->defaultUrl, strval($request->getUri()));
                return new Response(200);
            }
        ];
        $decodingOption = new UrlReplaceOption(false, []);
        $decodingOption->setEnabled(false);
        $this->executeMockRequest($mockResponse, $decodingOption);
    }

    /**
     * @throws GuzzleException
     */
    public function testUriReplaceWithSample(): void
    {
        $url = $this->defaultUrl.'/users/user-id-to-replace';
        $expectedDecoded = $this->defaultReplacedUrl.'/users/user-id-to-replace';

        $mockResponse = [
            function (RequestInterface $request, array $options) use ($expectedDecoded) {
                $this->assertEquals($expectedDecoded, strval($request->getUri()));
                return new Response(200);
            }
        ];
        $urlReplaceOption = new UrlReplaceOption(true, ['/users/user-id-to-replace' => '/me']);
        $this->executeMockRequest($mockResponse, $urlReplaceOption, $url);
    }

    /**
     * @throws GuzzleException
     */
    public function testUriReplaceWithSampleDisabled(): void
    {
        $url = $this->defaultUrl.'/users/user-id-to-replace';
        $expectedDecoded = $this->defaultUrl.'/users/user-id-to-replace';

        $mockResponse = [
            function (RequestInterface $request, array $options) use ($expectedDecoded) {
                $this->assertEquals($expectedDecoded, strval($request->getUri()));
                return new Response(200);
            }
        ];
        $urlReplaceOption = new UrlReplaceOption(false, ['/users/user-id-to-replace' => '/me']);
        $this->executeMockRequest($mockResponse, $urlReplaceOption, $url);
    }

    /**
     * @param array $mockResponses
     * @param ParametersDecodingOption|null $decodingOption
     * @param string|null $url
     * @param array<mixed> $requestOptions
     * @return ResponseInterface
     * @throws GuzzleException
     */
    private function executeMockRequest(array $mockResponses, ?UrlReplaceOption $decodingOption = null, string $url = null, array $requestOptions = []): ResponseInterface
    {
        $mockHandler = new MockHandler($mockResponses);
        $handlerStack = new HandlerStack($mockHandler);
        $decodingOption = $decodingOption ?: new UrlReplaceOption(true, ['/users/user-id-to-replace' => '/me']);
        $handlerStack->push(KiotaMiddleware::urlReplace($decodingOption));

        $guzzle = new Client(['handler' => $handlerStack]);
        $url = $url ?: $this->defaultUrl;
        return $guzzle->get($url, $requestOptions);
    }
}
