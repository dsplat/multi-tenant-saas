<?php

namespace MultiTenantSaas\Exceptions;

/**
 * 依赖服务不可用（未配置支付渠道、外部服务宕机）→ 503
 */
class ServiceUnavailableException extends DomainException
{
    protected int $statusCode = 503;
}
