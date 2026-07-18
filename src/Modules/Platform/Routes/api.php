<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Platform\Http\Controllers\TenantApplicationController;

// Operator 提交租户申请（需 Operator 认证）
Route::post('/operator/apply', [TenantApplicationController::class, 'apply']);

// Operator 查看自己的申请列表（需 Operator 认证）
Route::get('/operator/applications', [TenantApplicationController::class, 'myApplications']);
