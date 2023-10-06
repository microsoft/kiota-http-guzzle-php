<?php
/**
 * Copyright (c) Microsoft Corporation.  All Rights Reserved.
 * Licensed under the MIT License.  See License in the project root
 * for license information.
 */


namespace Microsoft\Kiota\Http\Middleware;


use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;
use Microsoft\Kiota\Http\Middleware\Options\ObservabilityOption;
use Microsoft\Kiota\Http\Middleware\Options\ParametersDecodingOption;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use Psr\Http\Message\RequestInterface;

/**
 * Class ParametersNameDecodingHandler
 *
 * This handler decodes special characters in the request query parameter names that had to be encoded due to RFC 6570
 * restrictions before executing the request.
 *
 * @package Microsoft\Kiota\Http\Middleware
 * @copyright 2022 Microsoft Corporation
 * @license https://opensource.org/licenses/MIT MIT License
 * @link https://developer.microsoft.com/graph
 */
class ParametersNameDecodingHandler
{
    /**
     * Handler name to reference within the handler stack
     */
    public const HANDLER_NAME = "kiotaParameterNamesDecodingHandler";
    /**
     * @var ParametersDecodingOption configuration for the middleware
     */
    private ParametersDecodingOption $decodingOption;
    /**
     * @var callable(RequestInterface, array<string,mixed>): PromiseInterface
     */
    private $nextHandler;

    /**
     * @param callable $nextHandler
     * @param ParametersDecodingOption|null $decodingOption
     */
    public function __construct(callable $nextHandler, ?ParametersDecodingOption $decodingOption = null)
    {
        $this->nextHandler = $nextHandler;
        $this->decodingOption = ($decodingOption) ?: new ParametersDecodingOption();
    }

    private const PARAMETERS_DECODING_HANDLER_ENABLED = 'com.microsoft.kiota.handler.parameters_name_decoding.enable';
    /**
     * @param RequestInterface $request
     * @param array<string, mixed> $options
     * @return PromiseInterface
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        $span = ObservabilityOption::getTracer()->spanBuilder('ParametersNameDecodingHandler_invoke?')
            ->startSpan();
        $scope = $span->activate();
        try {
            $span->setAttribute(self::PARAMETERS_DECODING_HANDLER_ENABLED, true);
            // Request-level options override global options
            if (array_key_exists(ParametersDecodingOption::class, $options) && $options[ParametersDecodingOption::class] instanceof ParametersDecodingOption) {
                $this->decodingOption = $options[ParametersDecodingOption::class];
            }
            $request = $this->decodeQueryParameters($request, $span);
            $fn  = $this->nextHandler;
            return $fn($request, $options);
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * @param RequestInterface $request
     * @param SpanInterface $span
     * @return RequestInterface
     */
    private function decodeQueryParameters(RequestInterface $request, SpanInterface $span): RequestInterface
    {
        $childSpan = ObservabilityOption::getTracer()->spanBuilder('decodeQueryParameters')
            ->setParent(Context::getCurrent())
            ->addLink($span->getContext())
            ->startSpan();
        try {
            if (!$this->decodingOption->isEnabled() || !$this->decodingOption->getParametersToDecode()) {
                return $request;
            }
            $decodedUri = self::decodeUriEncodedString($request->getUri(), $this->decodingOption->getParametersToDecode());
            return $request->withUri(new Uri($decodedUri));
        } finally {
            $childSpan->end();
        }
    }

    /**
     * @param string|null $original
     * @param array<string>|null $charactersToDecode
     * @return string
     */
    public static function decodeUriEncodedString(?string $original = null, ?array $charactersToDecode = null): string
    {
        if (empty($original) || empty($charactersToDecode)) {
            return $original ?? '';
        }
        $queryParams = parse_url($original, PHP_URL_QUERY);
        if (!$queryParams) {
            return $original;
        }
        $queryParamKeyValues = [];
        foreach (explode("&", $queryParams) as $nameValueString) {
            $nameVal = explode("=", $nameValueString);
            $queryParamKeyValues[$nameVal[0]] = $nameVal[1] ?? '';
        }
        $encodingsToReplace = array_map(fn ($character) => "%".dechex(ord($character)), $charactersToDecode);
        $decodedQueryParams = [];
        foreach ($queryParamKeyValues as $key => $val) {
            $decodedKey = str_ireplace($encodingsToReplace, $charactersToDecode, $key);
            $decodedQueryParams [] = "{$decodedKey}={$val}";
        }
        /** @returns string $decodedUri */
        return str_replace($queryParams, implode("&", $decodedQueryParams), $original);
    }
}
