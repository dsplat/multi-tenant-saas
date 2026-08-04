<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\DeveloperPortal\Http\Controllers\DeveloperPortalTenantController;

Route::prefix('tenant/developer')->group(function () {
    Route::get('/docs', [DeveloperPortalTenantController::class, 'docs']);
    Route::get('/sandbox', [DeveloperPortalTenantController::class, 'sandbox']);
});
