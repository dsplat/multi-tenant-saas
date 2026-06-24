<?php

namespace MultiTenantSaas\Exceptions;

use MultiTenantSaas\Enums\ErrorCode;
use RuntimeException;

class InsufficientCreditsException extends RuntimeException
{
    public ErrorCode $errorCode;

    public function __construct(?string $message = null, ErrorCode $errorCode = ErrorCode::InsufficientCredits, int $code = 0, ?\Throwable $previous = null)
    {
        $this->errorCode = $errorCode;
        parent::__construct($message ?? trans('credit.insufficient_balance'), $code, $previous);
    }
}
