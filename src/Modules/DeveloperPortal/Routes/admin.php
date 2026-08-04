<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\DeveloperPortal\Http\Controllers\Admin\DeveloperPortalAdminController;

Route::prefix('developer-portal')->group(function () {
    Route::get('/sandbox', [DeveloperPortalAdminController::class, 'sandboxIndex']);
    Route::post('/sandbox', [DeveloperPortalAdminController::class, 'sandboxStore']);
});
