<?php

namespace MultiTenantSaas\Exceptions;

use MultiTenantSaas\Enums\ErrorCode;

/**
 * 积分/额度不足 → 402
 */
class InsufficientCreditsException extends DomainException
{
    protected int $statusCode = 402;

    public ErrorCode $errorCode;

    public function __construct(?string $message = null, ErrorCode $errorCode = ErrorCode::InsufficientCredits, int $code = 0, ?\Throwable $previous = null)
    {
        $this->errorCode = $errorCode;
        parent::__construct($message ?? trans('credit.insufficient_balance'), $code, $previous);
    }
}
