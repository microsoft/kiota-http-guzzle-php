<?php
/**
 * Copyright (c) Microsoft Corporation.  All Rights Reserved.
 * Licensed under the MIT License.  See License in the project root
 * for license information.
 */


namespace Microsoft\Kiota\Http;


use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Http\Promise\FulfilledPromise;
use Http\Promise\Promise;
use League\Uri\Contracts\UriException;
use Microsoft\Kiota\Abstractions\ApiClientBuilder;
use Microsoft\Kiota\Abstractions\ApiException;
use Microsoft\Kiota\Abstractions\Authentication\AuthenticationProvider;
use Microsoft\Kiota\Abstractions\RequestAdapter;
use Microsoft\Kiota\Abstractions\RequestInformation;
use Microsoft\Kiota\Abstractions\Serialization\ParseNode;
use Microsoft\Kiota\Abstractions\Serialization\ParseNodeFactory;
use Microsoft\Kiota\Abstractions\Serialization\ParseNodeFactoryRegistry;
use Microsoft\Kiota\Abstractions\Serialization\SerializationWriterFactory;
use Microsoft\Kiota\Abstractions\Serialization\SerializationWriterFactoryRegistry;
use Microsoft\Kiota\Abstractions\Store\BackingStoreFactory;
use Microsoft\Kiota\Abstractions\Store\BackingStoreFactorySingleton;
use Microsoft\Kiota\Abstractions\Types\Date;
use Microsoft\Kiota\Abstractions\Types\Time;
use Microsoft\Kiota\Http\Middleware\Options\ResponseHandlerOption;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Class GuzzleRequestAdapter
 * @package Microsoft\Kiota\Http
 * @copyright 2022 Microsoft Corporation
 * @license https://opensource.org/licenses/MIT MIT License
 * @link https://developer.microsoft.com/graph
 */
class GuzzleRequestAdapter implements RequestAdapter
{
    /**
     * @var Client
     */
    private Client $guzzleClient;

    /**
     * @var AuthenticationProvider
     */
    private AuthenticationProvider $authenticationProvider;

    /**
     * @var ParseNodeFactory|ParseNodeFactoryRegistry
     */
    private ParseNodeFactory $parseNodeFactory;

    /**
     * @var SerializationWriterFactory|SerializationWriterFactoryRegistry
     */
    private SerializationWriterFactory $serializationWriterFactory;

    private string $baseUrl = '';

    /**
     * @param AuthenticationProvider $authenticationProvider
     * @param ParseNodeFactory|null $parseNodeFactory
     * @param SerializationWriterFactory|null $serializationWriterFactory
     * @param Client|null $guzzleClient
     */
    public function __construct(AuthenticationProvider $authenticationProvider,
                                ?ParseNodeFactory $parseNodeFactory = null,
                                ?SerializationWriterFactory $serializationWriterFactory = null,
                                ?Client $guzzleClient = null)
    {
        $this->authenticationProvider = $authenticationProvider;
        $this->parseNodeFactory = ($parseNodeFactory) ?: ParseNodeFactoryRegistry::getDefaultInstance();
        $this->serializationWriterFactory = ($serializationWriterFactory) ?: SerializationWriterFactoryRegistry::getDefaultInstance();
        $this->guzzleClient = ($guzzleClient) ?: KiotaClientFactory::create();
    }

    /**
     * @inheritDoc
     */
    public function sendAsync(RequestInformation $requestInfo, array $targetCallable, ?array $errorMappings = null): Promise
    {
        return $this->getHttpResponseMessage($requestInfo)->then(
            function (ResponseInterface $result) use ($targetCallable, $requestInfo, $errorMappings) {
                $responseHandlerOption = $requestInfo->getRequestOptions()[ResponseHandlerOption::class] ?? null;
                if ($responseHandlerOption && is_a($responseHandlerOption, ResponseHandlerOption::class)) {
                    return $responseHandlerOption->getResponseHandler()->handleResponseAsync($result, $errorMappings);
                }
                $this->throwFailedResponse($result, $errorMappings);
                if ($this->is204NoContentResponse($result)) {
                    return null;
                }
                $rootNode = $this->getRootParseNode($result);
                return $rootNode->getObjectValue($targetCallable);
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function getSerializationWriterFactory(): SerializationWriterFactory
    {
        return $this->serializationWriterFactory;
    }

    /**
     * @inheritDoc
     */
    public function getParseNodeFactory(): ParseNodeFactory
    {
        return $this->parseNodeFactory;
    }

    /**
     * @inheritDoc
     */
    public function sendCollectionAsync(RequestInformation $requestInfo, array $targetCallable, ?array $errorMappings = null): Promise
    {
        return $this->getHttpResponseMessage($requestInfo)->then(
            function (ResponseInterface $result) use ($targetCallable, $requestInfo, $errorMappings) {
                $responseHandlerOption = $requestInfo->getRequestOptions()[ResponseHandlerOption::class] ?? null;
                if ($responseHandlerOption && is_a($responseHandlerOption, ResponseHandlerOption::class)) {
                    return $responseHandlerOption->getResponseHandler()->handleResponseAsync($result, $errorMappings);
                }
                $this->throwFailedResponse($result, $errorMappings);
                if ($this->is204NoContentResponse($result)) {
                    return new FulfilledPromise(null);
                }
                return $this->getRootParseNode($result)->getCollectionOfObjectValues($targetCallable);
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function sendPrimitiveAsync(RequestInformation $requestInfo, string $primitiveType, ?array $errorMappings = null): Promise
    {
        return $this->getHttpResponseMessage($requestInfo)->then(
            function (ResponseInterface $result) use ($primitiveType, $requestInfo, $errorMappings) {
                $responseHandlerOption = $requestInfo->getRequestOptions()[ResponseHandlerOption::class] ?? null;
                if ($responseHandlerOption && is_a($responseHandlerOption, ResponseHandlerOption::class)) {
                    return $responseHandlerOption->getResponseHandler()->handleResponseAsync($result, $errorMappings);
                }
                $this->throwFailedResponse($result, $errorMappings);
                if ($this->is204NoContentResponse($result)) {
                    return null;
                }
                if ($primitiveType === StreamInterface::class) {
                    return $result->getBody();
                }
                $rootParseNode = $this->getRootParseNode($result);
                switch ($primitiveType) {
                    case 'int':
                    case 'long':
                        return $rootParseNode->getIntegerValue();
                    case 'float':
                        return $rootParseNode->getFloatValue();
                    case 'bool':
                        return $rootParseNode->getBooleanValue();
                    case 'string':
                        return $rootParseNode->getStringValue();
                    case \DateTime::class:
                        return $rootParseNode->getDateTimeValue();
                    case \DateInterval::class:
                        return $rootParseNode->getDateIntervalValue();
                    case Date::class:
                        return $rootParseNode->getDateValue();
                    case Time::class:
                        return $rootParseNode->getTimeValue();
                    default:
                        throw new \InvalidArgumentException("Unsupported primitive type $primitiveType");
                }
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function sendPrimitiveCollectionAsync(RequestInformation $requestInfo, string $primitiveType, ?array $errorMappings = null): Promise
    {
        return $this->getHttpResponseMessage($requestInfo)->then(
            function (ResponseInterface $result) use ($primitiveType, $requestInfo, $errorMappings) {
                $responseHandlerOption = $requestInfo->getRequestOptions()[ResponseHandlerOption::class] ?? null;
                if ($responseHandlerOption && is_a($responseHandlerOption, ResponseHandlerOption::class)) {
                    return $responseHandlerOption->getResponseHandler()->handleResponseAsync($result, $errorMappings);
                }
                $this->throwFailedResponse($result, $errorMappings);
                if ($this->is204NoContentResponse($result)) {
                    return null;
                }
                return $this->getRootParseNode($result)->getCollectionOfPrimitiveValues($primitiveType);
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function sendNoContentAsync(RequestInformation $requestInfo, ?array $errorMappings = null): Promise
    {
        return $this->getHttpResponseMessage($requestInfo)->then(
            function (ResponseInterface $result) use ($requestInfo, $errorMappings) {
                $responseHandlerOption = $requestInfo->getRequestOptions()[ResponseHandlerOption::class] ?? null;
                if ($responseHandlerOption && is_a($responseHandlerOption, ResponseHandlerOption::class)) {
                    return $responseHandlerOption->getResponseHandler()->handleResponseAsync($result, $errorMappings);
                }
                $this->throwFailedResponse($result, $errorMappings);
                return null;
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function enableBackingStore(BackingStoreFactory $backingStoreFactory): void
    {
        $this->parseNodeFactory = ApiClientBuilder::enableBackingStoreForParseNodeFactory($this->parseNodeFactory);
        $this->serializationWriterFactory = ApiClientBuilder::enableBackingStoreForSerializationWriterFactory($this->serializationWriterFactory);
        BackingStoreFactorySingleton::setInstance($backingStoreFactory);
    }

    /**
     * @inheritDoc
     */
    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = $baseUrl;
    }

    /**
     * @inheritDoc
     */
    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Create and returns a PSR 7 Request object from {@link RequestInformation}
     *
     * @param RequestInformation $requestInformation
     * @return RequestInterface
     * @throws UriException
     */
    public function getPsrRequestFromRequestInformation(RequestInformation $requestInformation): RequestInterface
    {
        $requestInformation->pathParameters["baseurl"] = $this->getBaseUrl();
        return new Request(
            $requestInformation->httpMethod,
            $requestInformation->getUri(),
            $requestInformation->getHeaders()->getAll(),
            $requestInformation->content
        );
    }

    /**
     * Converts RequestInformation object to an authenticated(containing auth header) PSR-7 Request Object.
     *
     * @param RequestInformation $request
     * @return Promise
     */
    public function convertToNative(RequestInformation $request): Promise
    {
        return $this->authenticationProvider->authenticateRequest($request)->then(
            fn (RequestInformation $authenticatedRequest): RequestInterface =>
                $this->getPsrRequestFromRequestInformation($authenticatedRequest)
        );
    }

    /**
     * Gets the root parse node using the parseNodeFactory based on the Content-Type
     *
     * @param ResponseInterface $response
     * @return ParseNode
     */
    private function getRootParseNode(ResponseInterface $response): ParseNode
    {
        if (!$response->hasHeader(RequestInformation::$contentTypeHeader)) {
            throw new \RuntimeException("No response content type header for deserialization");
        }
        $contentType = explode(';', $response->getHeaderLine(RequestInformation::$contentTypeHeader));
        if (!$contentType) {
            throw new \RuntimeException("Missing Content-Type header value");
        }
        return $this->parseNodeFactory->getRootParseNode($contentType[0], $response->getBody());
    }

    /**
     * Authenticates and executes the request
     *
     * @param RequestInformation $requestInformation
     * @return Promise
     */
    private function getHttpResponseMessage(RequestInformation $requestInformation): Promise
    {
        $requestInformation->pathParameters['baseurl'] = $this->getBaseUrl();
        $request = $this->authenticationProvider->authenticateRequest($requestInformation);
        return $request->then(
            function ($result) use ($requestInformation) {
                $psrRequest = $this->getPsrRequestFromRequestInformation($requestInformation);
                return $this->guzzleClient->send($psrRequest, $requestInformation->getRequestOptions());
            }
        );
    }

    /**
     * @param ResponseInterface $response
     * @param array<string, array{string, string}>|null $errorMappings
     * @throws ApiException
     */
    private function throwFailedResponse(ResponseInterface $response, ?array $errorMappings): void {
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 200 && $statusCode < 400) {
            return;
        }
        $statusCodeAsString = (string)$statusCode;
        if ($errorMappings === null || (!isset($errorMappings[$statusCodeAsString]) &&
            !($statusCode >= 400 && $statusCode < 500 && isset($errorMappings['4XX'])) &&
            !($statusCode >= 500 && $statusCode < 600 && isset($errorMappings["5XX"])))) {
            $ex = new ApiException("the server returned an unexpected status code and no error class is registered for this code " . $statusCode);
            $ex->setResponseStatusCode($statusCode);
            throw $ex;
        }
        /** @var array{string,string}|null $errorClass */
        $errorClass = $errorMappings[$statusCodeAsString] ?? ($errorMappings[$statusCodeAsString[0] . 'XX'] ?? null);

        try {
            $rootParseNode = $this->getRootParseNode($response);
            $error = $rootParseNode->getObjectValue($errorClass);
            if (is_subclass_of($error, ApiException::class)) {
                $error->setResponseStatusCode($statusCode);
                throw $error;
            }
            throw new ApiException("Unsupported error type ". get_debug_type($error));
        } catch (\RuntimeException $exception){
            throw new \RuntimeException("", 0, $exception);
        }
    }

    /**
     * @param ResponseInterface $response
     * @return bool
     */
    private function is204NoContentResponse(ResponseInterface $response): bool{
        return $response->getStatusCode() === 204;
    }
}
