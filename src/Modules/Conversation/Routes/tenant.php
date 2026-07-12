<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Conversation\Services\ConversationService;

Route::prefix('tenant/conversations')->group(function () {
    Route::get('/', function (Request $request) {
        $service = app(ConversationService::class);
        $tenantId = $request->attributes->get('tenant_id');
        $conversations = $service->listConversations($tenantId, $request->all());

        return response()->json(['success' => true, 'data' => $conversations]);
    });
    Route::post('/', function (Request $request) {
        $service = app(ConversationService::class);
        $tenantId = $request->attributes->get('tenant_id');
        $request->validate(['type' => 'required|string', 'participant_ids' => 'nullable|array']);
        $conversation = $service->createConversation($tenantId, $request->input('type'), $request->input('participant_ids', []));

        return response()->json(['success' => true, 'data' => $conversation], 201);
    });
});
