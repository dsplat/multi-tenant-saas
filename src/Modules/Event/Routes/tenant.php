<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Event\Http\Controllers\EventController;

Route::prefix('tenant/events')->group(function () {
    Route::get('/', [EventController::class, 'index']);
});
