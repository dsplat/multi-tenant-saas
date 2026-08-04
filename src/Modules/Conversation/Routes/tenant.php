<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Conversation\Http\Controllers\ConversationController;

Route::prefix('tenant/conversations')->group(function () {
    Route::get('/', [ConversationController::class, 'index']);
    Route::post('/', [ConversationController::class, 'store']);
});
