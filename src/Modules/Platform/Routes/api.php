<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Platform\Http\Controllers\AdminSettingsController;

Route::get('/admin/settings', [AdminSettingsController::class, 'index']);
Route::put('/admin/settings/{group}', [AdminSettingsController::class, 'update']);
