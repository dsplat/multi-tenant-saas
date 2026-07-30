<?php

namespace App\Http\Controllers\Concerns;

/**
 * API 响应统一格式 Trait
 *
 * @deprecated 已下沉为框架级 \MultiTenantSaas\Http\Concerns\ApiResponse，
 *             本 Trait 仅作向后兼容别名保留，新代码请直接继承
 *             \MultiTenantSaas\Http\Controllers\BaseController。
 */
trait ApiResponse
{
    use \MultiTenantSaas\Http\Concerns\ApiResponse;
}
