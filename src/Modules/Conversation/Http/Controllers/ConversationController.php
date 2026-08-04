<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Conversation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Conversation\Services\ConversationService;

/**
 * 租户端：会话管理
 */
class ConversationController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        if (! $tenantId) {
            return response()->json(['success' => false, 'message' => 'Tenant context required'], 422);
        }
        $service = app(ConversationService::class);
        $conversations = $service->listConversations($tenantId, $request->all());

        return response()->json(['success' => true, 'data' => $conversations]);
    }

    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        if (! $tenantId) {
            return response()->json(['success' => false, 'message' => 'Tenant context required'], 422);
        }
        $service = app(ConversationService::class);
        $request->validate(['type' => 'required|string', 'participant_ids' => 'nullable|array']);
        $conversation = $service->createConversation($tenantId, $request->input('type'), $request->input('participant_ids', []));

        return response()->json(['success' => true, 'data' => $conversation], 201);
    }
}
