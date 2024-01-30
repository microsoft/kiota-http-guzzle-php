<?php

namespace Microsoft\Kiota\Http\Exceptions;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

class NoContentTypeHeaderException extends RuntimeException
{

    private ResponseInterface $_response;

    public function __construct(string $message, ResponseInterface $response, int $code = 0, Throwable $previous = null)
    {
        $this->_response = $response;
        parent::__construct($message, 0, $previous);
    }

    public function getResponse(): ResponseInterface
    {
        return $this->_response;
    }

}
