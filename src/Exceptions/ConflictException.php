<?php

namespace MultiTenantSaas\Exceptions;

/**
 * 资源冲突（重复创建、并发修改）→ 409
 */
class ConflictException extends DomainException
{
    protected int $statusCode = 409;
}
