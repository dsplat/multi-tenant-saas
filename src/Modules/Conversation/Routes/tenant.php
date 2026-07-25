<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Conversation\Services\ConversationService;

Route::prefix('tenant/conversations')->group(function () {
    Route::get('/', function (Request $request) {
        $tenantId = $request->attributes->get('tenant_id');
        if (! $tenantId) {
            return response()->json(['success' => false, 'message' => 'Tenant context required'], 422);
        }
        $service = app(ConversationService::class);
        $conversations = $service->listConversations($tenantId, $request->all());

        return response()->json(['success' => true, 'data' => $conversations]);
    });
    Route::post('/', function (Request $request) {
        $tenantId = $request->attributes->get('tenant_id');
        if (! $tenantId) {
            return response()->json(['success' => false, 'message' => 'Tenant context required'], 422);
        }
        $service = app(ConversationService::class);
        $request->validate(['type' => 'required|string', 'participant_ids' => 'nullable|array']);
        $conversation = $service->createConversation($tenantId, $request->input('type'), $request->input('participant_ids', []));

        return response()->json(['success' => true, 'data' => $conversation], 201);
    });
});
