<?php

namespace MultiTenantSaas\Listeners;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Events\TenantActivated;
use MultiTenantSaas\Events\TenantCreated;
use MultiTenantSaas\Events\TenantSuspended;
use MultiTenantSaas\Events\UserLoggedIn;
use MultiTenantSaas\Events\UserRegistered;
use MultiTenantSaas\Services\AuditService;

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

    public function handleTenantCreated(TenantCreated $event): void
    {
        Log::info('Tenant created', ['tenant_id' => $event->tenant->tenant_id]);
        AuditService::log('create', 'tenant', $event->tenant->tenant_id, null, [
            'name' => $event->tenant->name,
            'slug' => $event->tenant->slug,
        ]);
    }

    public function handleTenantSuspended(TenantSuspended $event): void
    {
        Log::info('Tenant suspended', ['tenant_id' => $event->tenant->tenant_id]);
        AuditService::log('suspend', 'tenant', $event->tenant->tenant_id);
    }

    public function handleTenantActivated(TenantActivated $event): void
    {
        Log::info('Tenant activated', ['tenant_id' => $event->tenant->tenant_id]);
        AuditService::log('activate', 'tenant', $event->tenant->tenant_id);
    }

    public function handleUserRegistered(UserRegistered $event): void
    {
        Log::info('User registered', ['user_id' => $event->user->user_id, 'tenant_id' => $event->tenantId]);
        AuditService::log('register', 'user', $event->user->user_id, null, [
            'email' => $event->user->email,
            'tenant_id' => $event->tenantId,
        ]);
    }

    public function handleUserLoggedIn(UserLoggedIn $event): void
    {
        Log::info('User logged in', ['user_id' => $event->user->user_id, 'ip' => $event->ip]);
        AuditService::log('login', 'user', $event->user->user_id, null, [
            'ip' => $event->ip,
        ]);
    }
}
