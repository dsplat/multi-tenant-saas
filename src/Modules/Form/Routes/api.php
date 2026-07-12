<?php

use MultiTenantSaas\Modules\Form\Http\Controllers\FormController;

// ========== Form 表单 ==========
Route::prefix('/tenants/{tenantId}/forms')->group(function () {
    Route::get('/', [FormController::class, 'index'])->middleware('rbac.permission:form.view');
    Route::post('/', [FormController::class, 'store'])->middleware('rbac.permission:form.create');
    Route::get('/{formId}', [FormController::class, 'show'])->middleware('rbac.permission:form.view');
    Route::put('/{formId}', [FormController::class, 'update'])->middleware('rbac.permission:form.update');
    Route::delete('/{formId}', [FormController::class, 'destroy'])->middleware('rbac.permission:form.delete');
    Route::get('/{formId}/submissions', [FormController::class, 'submissions'])->middleware('rbac.permission:form.view');
    Route::get('/{formId}/statistics', [FormController::class, 'statistics'])->middleware('rbac.permission:form.view');
    Route::get('/{formId}/export', [FormController::class, 'export'])->middleware('rbac.permission:form.export');
});

Route::prefix('v1/forms')->group(function () {
    Route::post('/{formId}/submit', [FormController::class, 'submit'])->middleware('throttle:10,1');
});
