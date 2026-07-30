<?php

namespace MultiTenantSaas\Exceptions;

/**
 * 配额超限 → 429
 */
class QuotaExceededException extends DomainException
{
    protected int $statusCode = 429;
}
