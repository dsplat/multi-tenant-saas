<?php

use MultiTenantSaas\Http\Controllers\CapabilityController;
use MultiTenantSaas\Http\Controllers\ConversationController;
use MultiTenantSaas\Modules\Ai\Http\Controllers\AgentChatController;
use MultiTenantSaas\Modules\Ai\Http\Controllers\AgentController;
use MultiTenantSaas\Modules\Ai\Http\Controllers\AgentStatsController;
use MultiTenantSaas\Modules\Ai\Http\Controllers\AssistantController;
use MultiTenantSaas\Modules\Ai\Http\Controllers\ToolController;

// ========== Agent 管理 ==========
Route::middleware('rbac.permission:member.view')->group(function () {
    Route::get('/agents', [AgentController::class, 'index']);
    Route::get('/agents/templates', [AgentController::class, 'templates']);
    Route::post('/agents/templates/{templateId}/clone', [AgentController::class, 'cloneTemplate']);
    Route::get('/agents/{agentId}', [AgentController::class, 'show']);
});

Route::middleware('rbac.permission:member.create')->group(function () {
    Route::post('/agents', [AgentController::class, 'store']);
});

Route::middleware('rbac.permission:member.update')->group(function () {
    Route::put('/agents/{agentId}', [AgentController::class, 'update']);
    Route::post('/agents/{agentId}/enable', [AgentController::class, 'enable']);
    Route::post('/agents/{agentId}/disable', [AgentController::class, 'disable']);
    Route::put('/agents/{agentId}/model-config', [AgentController::class, 'updateModelConfig']);
    Route::put('/agents/{agentId}/tools', [AgentController::class, 'updateTools']);
    Route::put('/agents/{agentId}/knowledge-bases', [AgentController::class, 'updateKnowledgeBases']);
});

Route::middleware('rbac.permission:member.delete')->group(function () {
    Route::delete('/agents/{agentId}', [AgentController::class, 'destroy']);
});

// ========== Agent 对话 + SSE 流式 ==========
Route::middleware('rbac.permission:member.view')->group(function () {
    Route::post('/agents/{agentId}/chat', [AgentChatController::class, 'startChat']);
    Route::post('/agents/{agentId}/chat/{conversationId}', [AgentChatController::class, 'sendMessage']);
    Route::get('/agents/{agentId}/conversations', [AgentChatController::class, 'conversations']);
    Route::get('/conversations/{conversationId}', [AgentChatController::class, 'showConversation']);
    Route::get('/conversations/{conversationId}/messages', [AgentChatController::class, 'messages']);
});

Route::delete('/conversations/{conversationId}', [AgentChatController::class, 'deleteConversation'])
    ->middleware('rbac.permission:member.delete');

// ========== Agent 监控 ==========
Route::middleware('rbac.permission:member.view')->group(function () {
    Route::get('/agents/{agentId}/stats', [AgentStatsController::class, 'stats']);
    Route::get('/agents/{agentId}/token-usage', [AgentStatsController::class, 'tokenUsage']);
    Route::get('/agents/{agentId}/cost', [AgentStatsController::class, 'cost']);
    Route::get('/agents/{agentId}/tool-logs', [AgentStatsController::class, 'toolLogs']);
});

// ========== 工具管理 ==========
Route::middleware('rbac.permission:setting.view')->group(function () {
    Route::get('/tools', [ToolController::class, 'index']);
    Route::get('/tools/{slug}', [ToolController::class, 'show']);
});

Route::middleware('rbac.permission:setting.update')->group(function () {
    Route::post('/tools', [ToolController::class, 'store']);
    Route::put('/tools/{slug}', [ToolController::class, 'update']);
});

Route::middleware('rbac.permission:setting.delete')->group(function () {
    Route::delete('/tools/{slug}', [ToolController::class, 'destroy']);
});

// ========== Conversation Center ==========
Route::prefix('/conversations-center')->group(function () {
    Route::middleware('rbac.permission:member.view')->group(function () {
        Route::get('/', [ConversationController::class, 'index']);
        Route::post('/', [ConversationController::class, 'store']);
        Route::get('/{conversationId}', [ConversationController::class, 'show']);
        Route::post('/{conversationId}/close', [ConversationController::class, 'close']);
        Route::post('/{conversationId}/archive', [ConversationController::class, 'archive']);
        Route::get('/{conversationId}/messages', [ConversationController::class, 'messages']);
        Route::post('/{conversationId}/messages', [ConversationController::class, 'sendMessage']);
        Route::post('/{conversationId}/participants', [ConversationController::class, 'addParticipant']);
        Route::get('/{conversationId}/sessions', [ConversationController::class, 'sessions']);
        Route::post('/{conversationId}/sessions', [ConversationController::class, 'openSession']);
        Route::get('/{conversationId}/tags', [ConversationController::class, 'tags']);
        Route::post('/{conversationId}/tags', [ConversationController::class, 'addTag']);
        Route::post('/messages/{messageId}/reactions', [ConversationController::class, 'addReaction']);
        Route::post('/messages/{messageId}/read', [ConversationController::class, 'markAsRead']);
    });

    Route::middleware('rbac.permission:member.delete')->group(function () {
        Route::delete('/participants/{participantId}', [ConversationController::class, 'removeParticipant']);
        Route::post('/sessions/{sessionId}/close', [ConversationController::class, 'closeSession']);
        Route::delete('/messages/{messageId}/reactions/{emoji}', [ConversationController::class, 'removeReaction']);
    });
});

// ========== AI Capability ==========
Route::prefix('/capabilities')->middleware('rbac.permission:member.view')->group(function () {
    Route::get('/', [CapabilityController::class, 'index']);
    Route::post('/execute', [CapabilityController::class, 'execute']);
    Route::post('/batch', [CapabilityController::class, 'batch']);
});

// ========== AI 页面助手 ==========
Route::post('/ai/assistant', [AssistantController::class, 'handle'])
    ->middleware('tenant.ensure');
Route::get('/ai/assistant/availability', [AssistantController::class, 'availability'])
    ->middleware('tenant.ensure');
