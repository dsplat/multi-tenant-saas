<?php

use MultiTenantSaas\Modules\Ibot\Http\Controllers\IbotBindingController;

// ========== Ibot 随身助理绑定（operator 个人操作） ==========
Route::prefix('/tenants/{tenantId}/ibot')->group(function () {
    Route::get('/ibots', [IbotBindingController::class, 'indexIbots']);
    Route::post('/ibots/{ibotId}/bind-code', [IbotBindingController::class, 'generateBindCode']);
    Route::get('/bindings', [IbotBindingController::class, 'myBindings']);
    Route::delete('/bindings/{bindingId}', [IbotBindingController::class, 'revokeBinding']);
});
