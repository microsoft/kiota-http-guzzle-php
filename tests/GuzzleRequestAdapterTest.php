<?php

namespace Microsoft\Kiota\Http\Test;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Http\Promise\FulfilledPromise;
use Http\Promise\Promise;
use Microsoft\Kiota\Abstractions\ApiException;
use Microsoft\Kiota\Abstractions\Authentication\AuthenticationProvider;
use Microsoft\Kiota\Abstractions\Enum;
use Microsoft\Kiota\Abstractions\RequestInformation;
use Microsoft\Kiota\Abstractions\ResponseHandler;
use Microsoft\Kiota\Abstractions\Serialization\Parsable;
use Microsoft\Kiota\Abstractions\Serialization\ParseNode;
use Microsoft\Kiota\Abstractions\Serialization\ParseNodeFactory;
use Microsoft\Kiota\Abstractions\Serialization\SerializationWriter;
use Microsoft\Kiota\Abstractions\Serialization\SerializationWriterFactory;
use Microsoft\Kiota\Abstractions\Store\BackingStoreFactorySingleton;
use Microsoft\Kiota\Abstractions\Store\BackingStoreParseNodeFactory;
use Microsoft\Kiota\Abstractions\Store\BackingStoreSerializationWriterProxyFactory;
use Microsoft\Kiota\Abstractions\Store\InMemoryBackingStoreFactory;
use Microsoft\Kiota\Http\GuzzleRequestAdapter;
use Microsoft\Kiota\Http\Middleware\Options\ResponseHandlerOption;
use PHPUnit\Framework\TestCase;
use Microsoft\Kiota\Abstractions\NativeResponseHandler;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use UnexpectedValueException;

class GuzzleRequestAdapterTest extends TestCase
{
    private ParseNode $parseNode;
    private ParseNodeFactory $parseNodeFactory;
    private AuthenticationProvider $authenticationProvider;
    private RequestInformation $requestInformation;
    private string $baseUrl = "https:/graph.microsoft.com";
    private string $contentType = "application/json; odata.metadata=minimal; odata.streaming=true; IEEE754Compatible=false; charset=utf-8";

    protected function setUp(): void
    {
        $this->requestInformation = new RequestInformation();
        $this->requestInformation->urlTemplate = '';
        $this->requestInformation->httpMethod = 'GET';
        $this->requestInformation->addHeaders([
            'RequestId' => '1'
        ]);
        $this->requestInformation->content = Utils::streamFor('body');
        $this->requestInformation->setUri($this->baseUrl);

        $this->mockParseNode();
        $this->mockParseNodeFactory();
        $this->mockAuthenticationProvider();

        parent::setUp();
    }

    private function mockParseNode(): void
    {
        $this->parseNode = $this->createStub(ParseNode::class);
        $this->parseNode->method('getObjectValue')
                        ->willReturn(new TestUser(1));
        $this->parseNode->method('getCollectionOfObjectValues')
                        ->willReturn([new TestUser(1), new TestUser(2)]);
        $this->parseNode->method('getIntegerValue')
                        ->willReturn(1);
        $this->parseNode->method('getCollectionOfPrimitiveValues')
                        ->willReturn(['a', 'b', 'c']);
        $this->parseNode->method('getEnumValue')
                        ->willReturn($this->createMock(Enum::class));
    }

    private function mockParseNodeFactory(): void
    {
        $this->parseNodeFactory = $this->createStub(ParseNodeFactory::class);
        $this->parseNodeFactory->method('getRootParseNode')
                                ->willReturn($this->parseNode);
    }

    private function mockAuthenticationProvider(): void
    {
        $this->authenticationProvider = $this->createStub(AuthenticationProvider::class);
        $authenticatedRequest = $this->requestInformation;
        $authenticatedRequest->addHeader('Bearer', 'token');
        $this->authenticationProvider->method('authenticateRequest')
                                    ->willReturn(new FulfilledPromise($authenticatedRequest));
    }

    private function mockRequestAdapter(array $mockResponses = []): GuzzleRequestAdapter
    {
        $requestAdapter = new GuzzleRequestAdapter(
            $this->authenticationProvider,
            $this->parseNodeFactory,
            $this->createMock(SerializationWriterFactory::class),
            new Client(['handler' => new MockHandler($mockResponses)])
        );
        $requestAdapter->setBaseUrl($this->baseUrl);
        return $requestAdapter;
    }

    public function testGetPsrRequestFromRequestInformation(): void
    {
        $psrRequest = $this->mockRequestAdapter()->getPsrRequestFromRequestInformation($this->requestInformation);
        $this->assertEquals($this->requestInformation->httpMethod, $psrRequest->getMethod());
        $this->assertEquals($this->requestInformation->getHeaders()->get('RequestId')[0], $psrRequest->getHeaderLine('RequestId'));
        $this->assertEquals('body', $psrRequest->getBody()->getContents());
        $this->assertEquals($this->requestInformation->getUri(), (string)$psrRequest->getUri());
    }

    public function testSendAsync(): void
    {
        $requestAdapter = $this->mockRequestAdapter([new Response(200, ['Content-Type' => $this->contentType])]);
        $promise = $requestAdapter->sendAsync($this->requestInformation, array(TestUser::class, 'createFromDiscriminatorValue'));
        $this->assertInstanceOf(TestUser::class, $promise->wait());
    }

    public function testSendAsyncWithResponseHandler(): void
    {
        $requestAdapter = $this->mockRequestAdapter([
            new Response(200, ['Content-Type' => $this->contentType]),
            new Response(200, ['Content-Type' => $this->contentType])
        ]);
        $customResponseHandler = $this->createMock(ResponseHandler::class);
        $customResponseHandler->method('handleResponseAsync')->willReturn(new FulfilledPromise(new TestUser()));
        $this->requestInformation->addRequestOptions(new ResponseHandlerOption($customResponseHandler));
        $this->assertInstanceOf(TestUser::class, $requestAdapter->sendAsync($this->requestInformation, array(TestUser::class, 'createFromDiscriminatorValue'))->wait());

        $nativeResponseHandler = new NativeResponseHandler();
        $this->requestInformation->addRequestOptions(new ResponseHandlerOption($nativeResponseHandler));
        $this->assertNull($requestAdapter->sendAsync($this->requestInformation, array(TestUser::class, 'createFromDiscriminatorValue'))->wait());
        $this->assertInstanceOf(ResponseInterface::class, $nativeResponseHandler->getResponse());
    }

    public function testSendAsyncWithCustomResponseHandlerWithWrongReturnTypeThrowsException(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $requestAdapter = $this->mockRequestAdapter([
            new Response(200, ['Content-Type' => $this->contentType]),
            new Response(200, ['Content-Type' => $this->contentType])
        ]);
        $customResponseHandler = $this->createMock(ResponseHandler::class);
        $customResponseHandler->method('handleResponseAsync')->willReturn(new FulfilledPromise(100));
        $this->requestInformation->addRequestOptions(new ResponseHandlerOption($customResponseHandler));
        $requestAdapter->sendAsync($this->requestInformation, array(TestUser::class, 'createFromDiscriminatorValue'))->wait();
    }

    public function testSendAsyncWithNoContentResponseReturnsNull(): void
    {
        $requestAdapter = $this->mockRequestAdapter([
            new Response(204, ['Content-Type' => $this->contentType]),
        ]);
        $this->assertNull($requestAdapter->sendAsync($this->requestInformation, array(TestUser::class, 'createFromDiscriminatorValue'))->wait());
    }

    public function testSendAsyncWithNoResponseBodyReturnsNull(): void
    {
        foreach (range(200, 205) as $statusCode) {
            $requestAdapter = $this->mockRequestAdapter([
                new Response($statusCode)
            ]);
            $this->assertNull($requestAdapter->sendAsync($this->requestInformation, [TestUser::class, 'createFromDiscriminatorValue'])->wait());
        }
    }

    public function testSendCollectionAsync(): void
    {
        $requestAdapter = $this->mockRequestAdapter([new Response(200, ['Content-Type' => $this->contentType])]);
        $promise = $requestAdapter->sendCollectionAsync($this->requestInformation, array(TestUser::class, 'createFromDiscriminatorValue'));
        $result = $promise->wait();
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertInstanceOf(TestUser::class, $result[0]);
    }

    public function testSendCollectionAsyncWithNoContentResponseReturnsNull(): void
    {
        $requestAdapter = $this->mockRequestAdapter([
            new Response(204, ['Content-Type' => $this->contentType]),
        ]);
        $this->assertNull($requestAdapter->sendCollectionAsync($this->requestInformation, array(TestUser::class, 'createFromDiscriminatorValue'))->wait());
    }

    public function testSendCollectionAsyncWithResponseHandler(): void
    {
        $requestAdapter = $this->mockRequestAdapter([
            new Response(200, ['Content-Type' => 'application/json']),
        ]);
        $customResponseHandler = $this->createMock(ResponseHandler::class);
        $customResponseHandler->method('handleResponseAsync')->willReturn(new FulfilledPromise([new TestUser()]));
        $this->requestInformation->addRequestOptions(new ResponseHandlerOption($customResponseHandler));
        $result = $requestAdapter->sendCollectionAsync($this->requestInformation, array(TestUser::class, 'createFromDiscriminatorValue'))->wait();
        $this->assertIsArray($result);
        $this->assertEquals(1, sizeof($result));
        $this->assertInstanceOf(TestUser::class, $result[0]);

    }

    public function testSendCollectionAsyncWithCustomResponseHandlerReturnsNull(): void
    {
        $requestAdapter = $this->mockRequestAdapter([
            new Response(200, ['Content-Type' => 'application/json']),
        ]);
        $customResponseHandler = $this->createMock(ResponseHandler::class);
        $customResponseHandler->method('handleResponseAsync')->willReturn(new FulfilledPromise(null));
        $this->requestInformation->addRequestOptions(new ResponseHandlerOption($customResponseHandler));
        $result = $requestAdapter->sendCollectionAsync($this->requestInformation, array(TestUser::class, 'createFromDiscriminatorValue'))->wait();
        $this->assertNull($result);
    }

    public function testSendCollectionAsyncWithCustomResponseHandlerWithWrongReturnTypeThrowsException(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $requestAdapter = $this->mockRequestAdapter([
            new Response(200, ['Content-Type' => $this->contentType]),
            new Response(200, ['Content-Type' => $this->contentType])
        ]);
        $customResponseHandler = $this->createMock(ResponseHandler::class);
        $customResponseHandler->method('handleResponseAsync')->willReturn(new FulfilledPromise([new TestUser(), 'string']));
        $this->requestInformation->addRequestOptions(new ResponseHandlerOption($customResponseHandler));
        $requestAdapter->sendCollectionAsync($this->requestInformation, array(TestUser::class, 'createFromDiscriminatorValue'))->wait();
    }

    public function testSendCollectionAsyncWithNoResponseBodyReturnsNull(): void
    {
        foreach (range(200, 205) as $statusCode) {
            $requestAdapter = $this->mockRequestAdapter([
                new Response($statusCode)
            ]);
            $this->assertNull($requestAdapter->sendCollectionAsync($this->requestInformation, [TestUser::class, 'createFromDiscriminatorValue'])->wait());
        }
    }

    public function testSendPrimitiveAsync(): void
    {
        $requestAdapter = $this->mockRequestAdapter([new Response(200, ['Content-Type' => 'application/json'])]);
        $promise = $requestAdapter->sendPrimitiveAsync($this->requestInformation, 'int');
        $this->assertEquals(1, $promise->wait());
    }

    public function testSendPrimitiveAsyncThrowsExceptionForUnsupportedType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $requestAdapter = $this->mockRequestAdapter([new Response(200, ['Content-Type' => 'application/json'])]);
        $promise = $requestAdapter->sendPrimitiveAsync($this->requestInformation, 'callable');
        $promise->wait();
    }

    public function testSendPrimitiveAsyncWithNoContentResponseReturnsNull(): void
    {
        $requestAdapter = $this->mockRequestAdapter([
            new Response(204, ['Content-Type' => $this->contentType]),
        ]);
        $this->assertNull($requestAdapter->sendPrimitiveAsync($this->requestInformation, 'int')->wait());
    }

    public function testSendPrimitiveAsyncReturnsStream(): void
    {
        foreach ([200, 201, 202, 203, 206] as $statusCode) {
            $requestAdapter = $this->mockRequestAdapter([
                new Response($statusCode, ['Content-Type' => 'application/octet-stream'], Utils::streamFor('hello world')),
            ]);
            $result = $requestAdapter->sendPrimitiveAsync($this->requestInformation, StreamInterface::class)->wait();
            $this->assertInstanceOf(StreamInterface::class, $result);
            $this->assertEquals('hello world', $result->getContents());
        }
    }

    public function testSendPrimitiveAsyncExpectingStreamWithNoResponseBodyReturnsNull(): void
    {
        foreach (range(200, 205) as $statusCode) {
            $requestAdapter = $this->mockRequestAdapter([
                new Response($statusCode),
            ]);
            $result = $requestAdapter->sendPrimitiveAsync($this->requestInformation, StreamInterface::class)->wait();
            $this->assertNull($result);
        }
    }

    public function testSendPrimitiveAsyncWithNoResponseBodyReturnsNull(): void
    {
        foreach (range(200, 205) as $statusCode) {
            $requestAdapter = $this->mockRequestAdapter([
                new Response($statusCode),
            ]);
            $result = $requestAdapter->sendPrimitiveAsync($this->requestInformation, 'int')->wait();
            $this->assertNull($result);
        }
    }

    public function testSendPrimitiveAsyncWithResponseHandler(): void
    {
        $requestAdapter = $this->mockRequestAdapter([new Response(200, ['Content-Type' => 'application/json'])]);
        $customResponseHandler = $this->createMock(ResponseHandler::class);
        $customResponseHandler->method('handleResponseAsync')->willReturn(new FulfilledPromise(100));
        $this->requestInformation->addRequestOptions(new ResponseHandlerOption($customResponseHandler));
        $result = $requestAdapter->sendPrimitiveAsync($this->requestInformation, 'int')->wait();
        $this->assertIsInt($result);
        $this->assertEquals(100, $result);
    }

    public function testSendPrimitiveCollectionAsync(): void
    {
        $requestAdapter = $this->mockRequestAdapter([new Response(200, ['Content-Type' => 'application/json'])]);
        $promise = $requestAdapter->sendPrimitiveCollectionAsync($this->requestInformation, 'string');
        $result = $promise->wait();
        $this->assertIsArray($result);
        $this->assertTrue(sizeof($result) == 3);
        $this->assertTrue(is_string($result[0]));
    }

    public function testSendPrimitiveCollectionAsyncWithResponseHandler(): void
    {
        $requestAdapter = $this->mockRequestAdapter([new Response(200, ['Content-Type' => 'application/json'])]);
        $customResponseHandler = $this->createMock(ResponseHandler::class);
        $customResponseHandler->method('handleResponseAsync')->willReturn(new FulfilledPromise(['hello', 'world']));
        $this->requestInformation->addRequestOptions(new ResponseHandlerOption($customResponseHandler));
        $result = $requestAdapter->sendPrimitiveCollectionAsync($this->requestInformation, 'string')->wait();
        $this->assertIsArray($result);
        $this->assertEquals(2, sizeof($result));
        $this->assertEquals('hello', $result[0]);
    }

    public function testSendPrimitiveCollectionAsyncWithNoContentResponseReturnsNull(): void
    {
        $requestAdapter = $this->mockRequestAdapter([
            new Response(204, ['Content-Type' => $this->contentType]),
        ]);
        $this->assertNull($requestAdapter->sendPrimitiveCollectionAsync($this->requestInformation, 'int')->wait());
    }

    public function testSendPrimitiveCollectionAsyncWithCustomResponseHandlerWithWrongReturnTypeThrowsException(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $requestAdapter = $this->mockRequestAdapter([
            new Response(200, ['Content-Type' => $this->contentType]),
        ]);
        $customResponseHandler = $this->createMock(ResponseHandler::class);
        $customResponseHandler->method('handleResponseAsync')->willReturn(new FulfilledPromise('string'));
        $this->requestInformation->addRequestOptions(new ResponseHandlerOption($customResponseHandler));
        $requestAdapter->sendPrimitiveCollectionAsync($this->requestInformation, 'string')->wait();
    }

    public function testSendPrimitiveCollectionAsyncWithNoResponseBodyReturnsNull(): void
    {
        foreach (range(200, 205) as $statusCode) {
            $requestAdapter = $this->mockRequestAdapter([
                new Response($statusCode),
            ]);
            $result = $requestAdapter->sendPrimitiveCollectionAsync($this->requestInformation, 'int')->wait();
            $this->assertNull($result);
        }
    }

    public function testSendNoContentAsync(): void
    {
        $requestAdapter = $this->mockRequestAdapter([new Response(204, ['Content-Type' => 'application/json'])]);
        $promise = $requestAdapter->sendNoContentAsync($this->requestInformation);
        $this->assertNull($promise->wait());
    }

    public function testSendNoContentAsyncWithResponseHandler(): void
    {
        $requestAdapter = $this->mockRequestAdapter([new Response(200, ['Content-Type' => 'application/json'])]);
        $customResponseHandler = $this->createMock(ResponseHandler::class);
        $customResponseHandler->method('handleResponseAsync')->willReturn(new FulfilledPromise(null));
        $this->requestInformation->addRequestOptions(new ResponseHandlerOption($customResponseHandler));
        $this->assertNull($requestAdapter->sendNoContentAsync($this->requestInformation)->wait());
    }

    public function testSendNoContentAsyncWithCustomResponseHandlerWithWrongReturnTypeThrowsException(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $requestAdapter = $this->mockRequestAdapter([
            new Response(200, ['Content-Type' => $this->contentType]),
        ]);
        $customResponseHandler = $this->createMock(ResponseHandler::class);
        $customResponseHandler->method('handleResponseAsync')->willReturn(new FulfilledPromise('string'));
        $this->requestInformation->addRequestOptions(new ResponseHandlerOption($customResponseHandler));
        $requestAdapter->sendNoContentAsync($this->requestInformation)->wait();
    }

    public function testExceptionThrownOnErrorResponses(): void
    {
        $this->expectException(ApiException::class);
        $requestAdapter = $this->mockRequestAdapter([new Response(400, ['Content-Type' => 'application/json'])]);
        $requestAdapter->sendAsync($this->requestInformation, array(TestUser::class, 'createFromDiscriminatorValue'))->wait();
    }

    public function testExceptionThrownOnErrorResponsesXXX(): void
    {
        $this->parseNode = $this->createStub(ParseNode::class);
        $this->parseNodeFactory = $this->createStub(ParseNodeFactory::class);
        $this->parseNodeFactory->method('getRootParseNode')
            ->willReturn($this->parseNode);
        $mockError = new MockError("Failed");
        $this->parseNode->method('getObjectValue')
            ->willReturn($mockError);
        $this->expectException(MockError::class);
        $requestAdapter = $this->mockRequestAdapter([new Response(401, ['Content-Type' => 'application/json'], '{"message" : "Failed"}')]);
        $errorMappings = [
            'XXX' => [MockError::class, 'createFromDiscriminatorValue']
        ];
        $requestAdapter->sendAsync($this->requestInformation, [TestUser::class, 'createFromDiscriminatorValue'], $errorMappings)->wait();
    }

    public function testExceptionThrownOnErrorResponses4XX(): void
    {
        $this->parseNode = $this->createStub(ParseNode::class);
        $this->parseNodeFactory = $this->createStub(ParseNodeFactory::class);
        $this->parseNodeFactory->method('getRootParseNode')
            ->willReturn($this->parseNode);
        $mockError = new MockError("Failed");
        $this->parseNode->method('getObjectValue')
            ->willReturn($mockError);
        $this->expectException(MockError::class);
        $requestAdapter = $this->mockRequestAdapter([new Response(400, ['Content-Type' => 'application/json'], '{"message" : "Failed 4XX"}')]);
        $errorMappings = [
            '4XX' => [MockError::class, 'createFromDiscriminatorValue']
        ];
        $requestAdapter->sendAsync($this->requestInformation, [TestUser::class, 'createFromDiscriminatorValue'], $errorMappings)->wait();
    }

    public function testExceptionThrownOnErrorWithEmptyPayload(): void
    {
        $this->expectException(ApiException::class);
        $requestAdapter = $this->mockRequestAdapter([new Response(400)]);
        $requestAdapter->sendAsync($this->requestInformation, array(TestUser::class, 'createFromDiscriminatorValue'))->wait();
    }

    public function testCAEFailureCausesRetry(): void
    {
        $requestAdapter = $this->mockRequestAdapter(
            [
                new Response(401, [
                    'WWW-Authenticate' => 'Bearer authorization_uri="https://login.windows.net/common/oauth2/authorize",'.
                        'error="insufficient_claims",'.
                        'claims="eyJhY2Nlc3NfdG9rZW4iOnsibmJmIjp7ImVzc2VudGlhbCI6dHJ1ZSwgInZhbHVlIjoiMTYwNDEwNjY1MSJ0="'
                ]),
                function (Request $request) {
                    $this->assertEquals('body', $request->getBody()->getContents());
                    return new Response(200, ['Content-Type' => 'application/json']);
                }
            ]
        );
        $this->authenticationProvider->expects($this->exactly(2))
                                    ->method('authenticateRequest')
                                    ->withConsecutive(
                                        [$this->anything(), []],
                                        [$this->anything(), ['claims' => 'eyJhY2Nlc3NfdG9rZW4iOnsibmJmIjp7ImVzc2VudGlhbCI6dHJ1ZSwgInZhbHVlIjoiMTYwNDEwNjY1MSJ0=']]
                                    );
        $response = $requestAdapter->sendPrimitiveAsync($this->requestInformation, 'int')->wait();
        $this->assertEquals(1, $response);
    }

    public function testCAEFailureRetriesOnlyOnce(): void
    {
        $requestAdapter = $this->mockRequestAdapter(
            [
                new Response(401, [
                    'WWW-Authenticate' => 'Bearer authorization_uri="https://login.windows.net/common/oauth2/authorize",'.
                        'error="insufficient_claims",'.
                        'claims="eyJhY2Nlc3NfdG9rZW4iOnsibmJmIjp7ImVzc2VudGlhbCI6dHJ1ZSwgInZhbHVlIjoiMTYwNDEwNjY1MSJ0="'
                ]),
                new Response(401, [
                    'WWW-Authenticate' => 'Bearer authorization_uri="https://login.windows.net/common/oauth2/authorize",'.
                        'error="insufficient_claims",'.
                        'claims="eyJhY2Nlc3NfdG9rZW4iOnsibmJmIjp7ImVzc2VudGlhbCI6dHJ1ZSwgInZhbHVlIjoiMTYwNDEwNjY1MSJ0="'
                ]),
            ]
        );
        $this->expectException(ApiException::class);
        $this->authenticationProvider->expects($this->exactly(2))->method('authenticateRequest');
        $response = $requestAdapter->sendPrimitiveAsync($this->requestInformation, 'int')->wait();
    }


    public function testSendPrimitiveAsyncWithEnum(): void
    {
        $requestAdapter = $this->mockRequestAdapter([new Response(200, ['Content-Type' => 'application/json'])]);
        $promise = $requestAdapter->sendPrimitiveAsync($this->requestInformation, TestEnum::class);
        $this->assertInstanceOf(Enum::class, $promise->wait());
    }

    public function testEnableBackingStore(): void
    {
        $requestAdapter = $this->mockRequestAdapter();
        $requestAdapter->enableBackingStore(new InMemoryBackingStoreFactory());
        $this->assertInstanceOf(BackingStoreParseNodeFactory::class, $requestAdapter->getParseNodeFactory());
        $this->assertInstanceOf(BackingStoreSerializationWriterProxyFactory::class, $requestAdapter->getSerializationWriterFactory());
    }

    public function testConvertToNative(): void
    {
        $requestAdapter = $this->mockRequestAdapter();
        $request = $requestAdapter->convertToNative($this->requestInformation)->wait();
        $this->assertInstanceOf(RequestInterface::class, $request);
        $this->assertArrayHasKey('bearer',$request->getHeaders());
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals($this->baseUrl, strval($request->getUri()));
        $this->assertEquals('body', $request->getBody()->getContents());
    }

}

class TestUser implements Parsable {
    /**
     * @var array<string, mixed> $additionalData
     */
    private array $additionalData = [];
    private ?int $id;

    public function __construct(?int $id = null)
    {
        $this->id = $id;
    }

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFieldDeserializers(): array
    {
        return [
            "id" => function (self $o, ParseNode $n) {$o->setId($n->getIntegerValue());}
        ];
    }

    public function setId(?int $value): void {
        $this->id = $value;
    }

    public function serialize(SerializationWriter $writer): void
    {
        $writer->writeIntegerValue('id', $this->id);
    }

    public function getAdditionalData(): ?array {
        return $this->additionalData;
    }

    public function setAdditionalData(array $value): void
    {
        $this->additionalData = $value;
    }
    public static function createFromDiscriminatorValue(ParseNode $parseNode): TestUser {
        return new self();
    }
}

class TestEnum extends Enum
{
    const PASS = 'pass';
    const FAIL = 'fail';
}

class MockError extends ApiException implements Parsable
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
    public function getFieldDeserializers(): array
    {
        return [];
    }

    public function serialize(SerializationWriter $writer): void
    {
    }

    public function createFromDiscriminatorValue(ParseNode $parseNode): MockError
    {
        return new MockError("");
    }

}
