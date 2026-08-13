<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Platform\Http\Controllers\TenantApplicationController;

// 租户可选路由（已认证即可，tenant_id 允许为 null）：
// 无租户 Operator 的申请创建租户流程，不挂 tenant.ensure；
// 归属门禁由模块组的 VerifyOperatorTenant 负责（无显式租户时放行）。

// Operator 提交租户申请（需 Operator 认证）
Route::post('/operator/apply', [TenantApplicationController::class, 'apply']);

// Operator 查看自己的申请列表（需 Operator 认证）
Route::get('/operator/applications', [TenantApplicationController::class, 'myApplications']);
