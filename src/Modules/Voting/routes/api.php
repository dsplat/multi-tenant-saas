<?php

use MultiTenantSaas\Modules\Voting\Http\Controllers\VotingController;

// ========== Voting 投票 ==========
Route::prefix('/tenants/{tenantId}/voting')->group(function () {
    Route::get('/', [VotingController::class, 'index'])->middleware('rbac.permission:voting.view');
    Route::post('/', [VotingController::class, 'store'])->middleware('rbac.permission:voting.create');
    Route::get('/{voteId}', [VotingController::class, 'show'])->middleware('rbac.permission:voting.view');
    Route::put('/{voteId}', [VotingController::class, 'update'])->middleware('rbac.permission:voting.update');
    Route::delete('/{voteId}', [VotingController::class, 'destroy'])->middleware('rbac.permission:voting.delete');
    Route::post('/{voteId}/cast', [VotingController::class, 'castVote'])->middleware(['rbac.permission:voting.vote', 'throttle:5,1']);
    Route::get('/{voteId}/ranking', [VotingController::class, 'ranking'])->middleware('rbac.permission:voting.view');
    Route::get('/{voteId}/statistics', [VotingController::class, 'statistics'])->middleware('rbac.permission:voting.view');
    Route::get('/{voteId}/records', [VotingController::class, 'records'])->middleware('rbac.permission:voting.view');
});
