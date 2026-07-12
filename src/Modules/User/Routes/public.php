<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\User\Http\Controllers\TenantOnboardingController;

Route::post('/tenants/register', [TenantOnboardingController::class, 'register']);
Route::post('/tenants/onboarding/status', [TenantOnboardingController::class, 'status']);
Route::post('/tenants/onboarding/complete', [TenantOnboardingController::class, 'complete']);
Route::post('/tenants/onboarding/{step}', [TenantOnboardingController::class, 'saveStep']);
