<?php

namespace MultiTenantSaas\Exceptions;

/**
 * 权限不足 / 跨租户访问被拒 → 403
 */
class PermissionDeniedException extends DomainException
{
    protected int $statusCode = 403;
}
