<?php
/**
 * Copyright (c) Microsoft Corporation.  All Rights Reserved.
 * Licensed under the MIT License.  See License in the project root
 * for license information.
 */


namespace Microsoft\Kiota\Http\Middleware\Options;


use Microsoft\Kiota\Abstractions\RequestHeaders;
use Microsoft\Kiota\Abstractions\RequestOption;
use Microsoft\Kiota\Http\Middleware\HeadersInspectionHandler;

/**
 * Class HeadersInspectionHandlerOption
 *
 * Configurations for a {@link HeadersInspectionHandler}
 *
 * @package Microsoft\Kiota\Http\Middleware\Options
 * @copyright 2023 Microsoft Corporation
 * @license https://opensource.org/licenses/MIT MIT License
 */
class HeadersInspectionHandlerOption implements RequestOption
{
    /**
     * @var bool
     */
    private bool $inspectResponseHeaders = false;

    /**
     * @var bool
     */
    private bool $inspectRequestHeaders = false;

    /**
     * @var RequestHeaders
     */
    private RequestHeaders $requestHeaders;

    /**
     * @var RequestHeaders
     */
    private RequestHeaders $responseHeaders;


    /**
     * @param bool $inspectResponseHeaders Stores the raw response headers when true. Defaults to false.
     * @param bool $inspectRequestHeaders Stores the raw request headers when true. Defaults to false.
     */
    public function __construct(bool $inspectResponseHeaders = false, bool $inspectRequestHeaders = false)
    {
        $this->inspectResponseHeaders = $inspectResponseHeaders;
        $this->inspectRequestHeaders = $inspectRequestHeaders;
        $this->requestHeaders = new RequestHeaders();
        $this->responseHeaders = new RequestHeaders();
    }

    /**
     * @return bool
     */
    public function isInspectResponseHeaders(): bool
    {
        return $this->inspectResponseHeaders;
    }

    /**
     * @return bool
     */
    public function isInspectRequestHeaders(): bool
    {
        return $this->inspectRequestHeaders;
    }

    /**
     * @return RequestHeaders
     */
    public function getRequestHeaders(): RequestHeaders
    {
        return $this->requestHeaders;
    }

    /**
     * @param RequestHeaders $requestHeaders
     */
    public function setRequestHeaders(RequestHeaders $requestHeaders): void
    {
        $this->requestHeaders = $requestHeaders;
    }

    /**
     * @return RequestHeaders
     */
    public function getResponseHeaders(): RequestHeaders
    {
        return $this->responseHeaders;
    }

    /**
     * @param RequestHeaders $responseHeaders
     */
    public function setResponseHeaders(RequestHeaders $responseHeaders): void
    {
        $this->responseHeaders = $responseHeaders;
    }
}
