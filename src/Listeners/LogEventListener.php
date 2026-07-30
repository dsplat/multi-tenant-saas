<?php

namespace MultiTenantSaas\Listeners;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Events\AgentCreated;
use MultiTenantSaas\Events\AgentDisabled;
use MultiTenantSaas\Events\AgentEnabled;
use MultiTenantSaas\Events\TenantActivated;
use MultiTenantSaas\Events\TenantCreated;
use MultiTenantSaas\Events\TenantSuspended;
use MultiTenantSaas\Events\ToolCallFailed;
use MultiTenantSaas\Events\UserLoggedIn;
use MultiTenantSaas\Events\UserRegistered;
use MultiTenantSaas\Modules\Logging\Services\AuditService;

/**
 * 事件监听器 — 将领域事件记录到审计日志和系统日志
 *
 * 派生项目可添加自己的监听器响应同一事件（如发邮件、推送通知等），
 * 也可在 TenancyServiceProvider 中替换或禁用此监听器。
 */
class LogEventListener
{
    /**
     * 仅在事务提交后执行，避免回滚时记录幽灵状态
     */
    public bool $afterCommit = true;

    public function __construct(private readonly AuditService $audit) {}

    /**
     * 审计写入是旁路副作用：监听器在业务主流程内同步触发，
     * 写入失败（如派生项目未安装 logging 模块表）只记错误日志，不得中断主流程。
     */
    private function audit(string $action, string $resourceType, int|string|null $resourceId = null, ?array $oldValues = null, ?array $newValues = null): void
    {
        try {
            $this->audit->log($action, $resourceType, $resourceId, $oldValues, $newValues);
        } catch (\Throwable $e) {
            Log::error('LogEventListener: 审计写入失败', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    public function handleTenantCreated(TenantCreated $event): void
    {
        Log::info('Tenant created', ['tenant_id' => $event->tenant->tenant_id]);
        $this->audit('create', 'tenant', $event->tenant->tenant_id, null, [
            'name' => $event->tenant->name,
            'slug' => $event->tenant->slug,
        ]);
    }

    public function handleTenantSuspended(TenantSuspended $event): void
    {
        Log::info('Tenant suspended', ['tenant_id' => $event->tenant->tenant_id]);
        $this->audit('suspend', 'tenant', $event->tenant->tenant_id);
    }

    public function handleTenantActivated(TenantActivated $event): void
    {
        Log::info('Tenant activated', ['tenant_id' => $event->tenant->tenant_id]);
        $this->audit('activate', 'tenant', $event->tenant->tenant_id);
    }

    public function handleUserRegistered(UserRegistered $event): void
    {
        Log::info('User registered', ['user_id' => $event->user->user_id, 'tenant_id' => $event->tenantId]);
        $this->audit('register', 'user', $event->user->user_id, null, [
            'email' => $event->user->email,
            'tenant_id' => $event->tenantId,
        ]);
    }

    public function handleUserLoggedIn(UserLoggedIn $event): void
    {
        Log::info('User logged in', ['user_id' => $event->user->user_id, 'ip' => $event->ip]);
        $this->audit('login', 'user', $event->user->user_id, null, [
            'ip' => $event->ip,
        ]);
    }

    public function handleAgentCreated(AgentCreated $event): void
    {
        Log::info('Agent created', ['tenant_id' => $event->tenantId, 'agent_id' => $event->agentId]);
        $this->audit('create', 'agent', $event->agentId, null, [
            'tenant_id' => $event->tenantId,
        ]);
    }

    public function handleAgentEnabled(AgentEnabled $event): void
    {
        Log::info('Agent enabled', ['tenant_id' => $event->tenantId, 'agent_id' => $event->agentId]);
        $this->audit('enable', 'agent', $event->agentId, null, [
            'tenant_id' => $event->tenantId,
        ]);
    }

    public function handleAgentDisabled(AgentDisabled $event): void
    {
        Log::info('Agent disabled', ['tenant_id' => $event->tenantId, 'agent_id' => $event->agentId]);
        $this->audit('disable', 'agent', $event->agentId, null, [
            'tenant_id' => $event->tenantId,
        ]);
    }

    public function handleToolCallFailed(ToolCallFailed $event): void
    {
        $error = $event->error instanceof \Throwable ? $event->error->getMessage() : $event->error;

        Log::warning('Tool call failed', [
            'tenant_id' => $event->tenantId,
            'agent_id' => $event->agentId,
            'conversation_id' => $event->conversationId,
            'tool' => $event->toolName,
            'error' => $error,
        ]);

        $this->audit('tool_call_failed', 'agent', $event->agentId, null, [
            'tenant_id' => $event->tenantId,
            'conversation_id' => $event->conversationId,
            'tool' => $event->toolName,
            'error' => $error,
        ]);
    }
}
