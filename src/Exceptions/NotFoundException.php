<?php

namespace MultiTenantSaas\Exceptions;

/**
 * 资源不存在 → 404
 */
class NotFoundException extends DomainException
{
    protected int $statusCode = 404;
}
