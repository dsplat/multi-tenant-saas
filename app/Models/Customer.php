<?php

namespace App\Models;

use MultiTenantSaas\Models\Tenant;

/**
 * 示例客户模型
 *
 * 继承 Tenant 基类，自动获得租户隔离能力
 */
class Customer extends Tenant
{
    protected $primaryKey = 'customer_id';

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'phone',
        'status',
    ];

    protected $casts = [
        'status' => 'string',
    ];
}
