<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Logging\Http\Controllers\TenantAuditController;

Route::get('/tenants/{tenantId}/audit-logs', [TenantAuditController::class, 'index']);
