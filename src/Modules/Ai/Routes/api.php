<?php

use MultiTenantSaas\Modules\Ai\Http\Controllers\AgentChatController;
use MultiTenantSaas\Modules\Ai\Http\Controllers\AgentController;
use MultiTenantSaas\Modules\Ai\Http\Controllers\AgentStatsController;
use MultiTenantSaas\Modules\Ai\Http\Controllers\ToolController;
use MultiTenantSaas\Http\Controllers\CapabilityController;
use MultiTenantSaas\Http\Controllers\ConversationController;

// ========== Agent 管理 ==========
Route::get('/agents', [AgentController::class, 'index']);
Route::get('/agents/templates', [AgentController::class, 'templates']);
Route::post('/agents/templates/{templateId}/clone', [AgentController::class, 'cloneTemplate']);
Route::get('/agents/{agentId}', [AgentController::class, 'show']);
Route::post('/agents', [AgentController::class, 'store']);
Route::put('/agents/{agentId}', [AgentController::class, 'update']);
Route::delete('/agents/{agentId}', [AgentController::class, 'destroy']);
Route::post('/agents/{agentId}/enable', [AgentController::class, 'enable']);
Route::post('/agents/{agentId}/disable', [AgentController::class, 'disable']);
Route::put('/agents/{agentId}/model-config', [AgentController::class, 'updateModelConfig']);
Route::put('/agents/{agentId}/tools', [AgentController::class, 'updateTools']);
Route::put('/agents/{agentId}/knowledge-bases', [AgentController::class, 'updateKnowledgeBases']);

// ========== Agent 对话 + SSE 流式 ==========
Route::post('/agents/{agentId}/chat', [AgentChatController::class, 'startChat']);
Route::post('/agents/{agentId}/chat/{conversationId}', [AgentChatController::class, 'sendMessage']);
Route::get('/agents/{agentId}/conversations', [AgentChatController::class, 'conversations']);
Route::get('/conversations/{conversationId}', [AgentChatController::class, 'showConversation']);
Route::get('/conversations/{conversationId}/messages', [AgentChatController::class, 'messages']);
Route::delete('/conversations/{conversationId}', [AgentChatController::class, 'deleteConversation']);

// ========== Agent 监控 ==========
Route::get('/agents/{agentId}/stats', [AgentStatsController::class, 'stats']);
Route::get('/agents/{agentId}/token-usage', [AgentStatsController::class, 'tokenUsage']);
Route::get('/agents/{agentId}/cost', [AgentStatsController::class, 'cost']);
Route::get('/agents/{agentId}/tool-logs', [AgentStatsController::class, 'toolLogs']);

// ========== 工具管理 ==========
Route::get('/tools', [ToolController::class, 'index']);
Route::get('/tools/{slug}', [ToolController::class, 'show']);
Route::post('/tools', [ToolController::class, 'store']);
Route::put('/tools/{slug}', [ToolController::class, 'update']);
Route::delete('/tools/{slug}', [ToolController::class, 'destroy']);

// ========== Conversation Center ==========
Route::prefix('/conversations-center')->group(function () {
    Route::get('/', [ConversationController::class, 'index']);
    Route::post('/', [ConversationController::class, 'store']);
    Route::get('/{conversationId}', [ConversationController::class, 'show']);
    Route::post('/{conversationId}/close', [ConversationController::class, 'close']);
    Route::post('/{conversationId}/archive', [ConversationController::class, 'archive']);
    Route::get('/{conversationId}/messages', [ConversationController::class, 'messages']);
    Route::post('/{conversationId}/messages', [ConversationController::class, 'sendMessage']);
    Route::post('/{conversationId}/participants', [ConversationController::class, 'addParticipant']);
    Route::delete('/participants/{participantId}', [ConversationController::class, 'removeParticipant']);
    Route::get('/{conversationId}/sessions', [ConversationController::class, 'sessions']);
    Route::post('/{conversationId}/sessions', [ConversationController::class, 'openSession']);
    Route::post('/sessions/{sessionId}/close', [ConversationController::class, 'closeSession']);
    Route::get('/{conversationId}/tags', [ConversationController::class, 'tags']);
    Route::post('/{conversationId}/tags', [ConversationController::class, 'addTag']);
    Route::post('/messages/{messageId}/reactions', [ConversationController::class, 'addReaction']);
    Route::delete('/messages/{messageId}/reactions/{emoji}', [ConversationController::class, 'removeReaction']);
    Route::post('/messages/{messageId}/read', [ConversationController::class, 'markAsRead']);
});

// ========== AI Capability ==========
Route::prefix('/capabilities')->group(function () {
    Route::get('/', [CapabilityController::class, 'index']);
    Route::post('/execute', [CapabilityController::class, 'execute']);
    Route::post('/batch', [CapabilityController::class, 'batch']);
});
