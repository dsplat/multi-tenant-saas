<?php

namespace MultiTenantSaas\Exceptions;

/**
 * 存储层故障（写入/读取失败）→ 500
 */
class StorageException extends DomainException
{
    protected int $statusCode = 500;
}
