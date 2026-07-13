<?php

declare(strict_types=1);

namespace MultiTenantSaas\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Conversation Center — stub controller
 *
 * 路由已在 src/Routes/ai.php 和 src/Modules/Ai/Routes/api.php 中注册，
 * 返回空数据 stub，后续填充业务逻辑。
 */
class ConversationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [], 'total' => 0]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'channel' => 'nullable|string|max:50',
        ]);

        return response()->json(['success' => true, 'data' => array_merge($validated, ['id' => 1, 'status' => 'open'])], 201);
    }

    public function show(int $conversationId): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['id' => $conversationId, 'title' => '', 'status' => 'open']]);
    }

    public function close(int $conversationId): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['id' => $conversationId, 'status' => 'closed']]);
    }

    public function archive(int $conversationId): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['id' => $conversationId, 'status' => 'archived']]);
    }

    public function messages(int $conversationId): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [], 'total' => 0]);
    }

    public function sendMessage(Request $request, int $conversationId): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['id' => 1, 'conversation_id' => $conversationId, 'content' => '']], 201);
    }

    public function addParticipant(Request $request, int $conversationId): JsonResponse
    {
        return response()->json(['success' => true, 'data' => null], 201);
    }

    public function removeParticipant(int $participantId): JsonResponse
    {
        return response()->json(null, 204);
    }

    public function sessions(int $conversationId): JsonResponse
    {
        return response()->json(['success' => true, 'data' => []]);
    }

    public function openSession(Request $request, int $conversationId): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['id' => 1, 'conversation_id' => $conversationId]], 201);
    }

    public function closeSession(int $sessionId): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['id' => $sessionId, 'status' => 'closed']]);
    }

    public function tags(int $conversationId): JsonResponse
    {
        return response()->json(['success' => true, 'data' => []]);
    }

    public function addTag(Request $request, int $conversationId): JsonResponse
    {
        return response()->json(['success' => true, 'data' => null], 201);
    }

    public function addReaction(Request $request, int $messageId): JsonResponse
    {
        return response()->json(['success' => true, 'data' => null], 201);
    }

    public function removeReaction(int $messageId, string $emoji): JsonResponse
    {
        return response()->json(null, 204);
    }

    public function markAsRead(Request $request, int $messageId): JsonResponse
    {
        return response()->json(['success' => true, 'data' => null]);
    }
}
