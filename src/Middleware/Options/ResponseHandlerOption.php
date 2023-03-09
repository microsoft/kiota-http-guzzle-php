<?php
/**
 * Copyright (c) Microsoft Corporation.  All Rights Reserved.
 * Licensed under the MIT License.  See License in the project root
 * for license information.
 */


namespace Microsoft\Kiota\Http\Middleware\Options;


use Microsoft\Kiota\Abstractions\RequestOption;
use Microsoft\Kiota\Abstractions\ResponseHandler;

/**
 * Class ResponseHandlerOption
 *
 * Configure a custom response handler for the request
 *
 * @package Microsoft\Kiota\Http\Middleware\Options
 * @copyright 2023 Microsoft Corporation
 * @license https://opensource.org/licenses/MIT MIT License
 */
class ResponseHandlerOption implements RequestOption
{
    /**
     * @var ResponseHandler
     */
    private ResponseHandler $responseHandler;

    /**
     * @param ResponseHandler $responseHandler custom response handler
     */
    public function __construct(ResponseHandler $responseHandler)
    {
        $this->responseHandler = $responseHandler;
    }

    /**
     * @return ResponseHandler
     */
    public function getResponseHandler(): ResponseHandler
    {
        return $this->responseHandler;
    }
}
