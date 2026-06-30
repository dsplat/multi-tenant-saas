<?php

namespace MultiTenantSaas\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;
use MultiTenantSaas\Contracts\EventHandler;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Jobs\DispatchEventJob;
use MultiTenantSaas\Models\DeadLetter;
use MultiTenantSaas\Models\EventSubscription;

/**
 * 事件总线服务
 *
 * 功能：
 *  - 事件发布 / 订阅管理（CRUD）
 *  - 内部订阅（Service 处理器）与外部订阅（Webhook 分发）
 *  - 事件路由（按事件类型分发到订阅者）
 *  - 异步分发（通过队列 DispatchEventJob）
 *  - 死信队列管理（查询 / 重投 / 标记解决 / 删除）
 *
 * 集成：
 *  - 与 Laravel 原生 Event 系统集成：publish() 同时触发原生事件分发，兼容现有 Events。
 *  - 与 TASK-019 WebhookService 集成：复用预定义事件类型与 HMAC-SHA256 签名机制。
 */
class EventBusService
{
    public function __construct(
        protected WebhookService $webhookService
    ) {}

    /**
     * 获取所有预定义事件类型（复用 WebhookService）
     *
     * @return array<int, string>
     */
    public function getSupportedEvents(): array
    {
        return $this->webhookService->getSupportedEvents();
    }

    /**
     * 校验事件类型是否受支持
     */
    public function isSupportedEvent(string $eventType): bool
    {
        return $this->webhookService->isSupportedEvent($eventType);
    }

    // ----------------------------------------
    // 订阅管理
    // ----------------------------------------

    /**
     * 注册事件订阅
     *
     * @param  string  $handler  内部处理器类名 或 外部 Webhook URL
     */
    public function subscribe(
        string $eventType,
        string $handler,
        string $type = EventSubscription::TYPE_INTERNAL,
        ?string $description = null,
        bool $isActive = true
    ): EventSubscription {
        $this->assertSupportedEvent($eventType);
        $this->assertSubscriptionType($type);
        $this->assertHandler($handler, $type);

        return EventSubscription::create([
            'event_type' => $eventType,
            'subscription_type' => $type,
            'handler' => $handler,
            'secret' => $type === EventSubscription::TYPE_WEBHOOK ? $this->generateSecret() : null,
            'is_active' => $isActive,
            'description' => $description,
        ]);
    }

    /**
     * 注销订阅
     */
    public function unsubscribe(int $id): bool
    {
        $subscription = $this->findSubscription($id);
        if (! $subscription) {
            return false;
        }

        $subscription->delete();

        return true;
    }

    /**
     * 查找单个订阅
     */
    public function findSubscription(int $id): ?EventSubscription
    {
        return EventSubscription::where('event_subscription_id', $id)->first();
    }

    /**
     * 订阅列表（可按事件类型 / 订阅类型过滤）
     *
     * @return Collection<int, EventSubscription>
     */
    public function listSubscriptions(?string $eventType = null, ?string $type = null): Collection
    {
        $query = EventSubscription::query();

        if ($eventType !== null) {
            $query->where('event_type', $eventType);
        }
        if ($type !== null) {
            $query->where('subscription_type', $type);
        }

        return $query->orderByDesc('created_at')->get();
    }

    /**
     * 激活订阅
     */
    public function activateSubscription(int $id): ?EventSubscription
    {
        return $this->toggleSubscription($id, true);
    }

    /**
     * 停用订阅
     */
    public function deactivateSubscription(int $id): ?EventSubscription
    {
        return $this->toggleSubscription($id, false);
    }

    // ----------------------------------------
    // 事件发布
    // ----------------------------------------

    /**
     * 发布事件
     *
     * 1. 同步触发 Laravel 原生事件，兼容原生 Event 监听器；
     * 2. 异步分发到所有匹配的活跃订阅者（每个订阅者独立任务，互不影响）。
     *
     * @param  array<string, mixed>  $payload  事件数据
     * @param  int  $delay  延迟分发秒数（0 表示不延迟）
     * @return int 分发的订阅者数量
     */
    public function publish(string $eventType, array $payload = [], int $delay = 0): int
    {
        // 1. 与 Laravel 原生 Event 系统集成（同步）
        Event::dispatch($eventType, ['type' => $eventType, 'payload' => $payload]);

        // 2. 异步路由到总线订阅者
        $tenantId = TenantContext::getId();

        $subscriptions = EventSubscription::where('event_type', $eventType)
            ->where('is_active', true)
            ->get();

        foreach ($subscriptions as $subscription) {
            $pending = DispatchEventJob::dispatch(
                $eventType,
                $payload,
                $tenantId,
                $subscription->event_subscription_id
            );

            if ($delay > 0) {
                $pending->delay($delay);
            }
        }

        return $subscriptions->count();
    }

    // ----------------------------------------
    // 死信队列
    // ----------------------------------------

    /**
     * 死信列表（可按事件类型过滤）
     *
     * @return Collection<int, DeadLetter>
     */
    public function getDeadLetters(?string $eventType = null): Collection
    {
        $query = DeadLetter::query();

        if ($eventType !== null) {
            $query->where('event_type', $eventType);
        }

        return $query->orderByDesc('created_at')->get();
    }

    /**
     * 查找单条死信
     */
    public function findDeadLetter(int $id): ?DeadLetter
    {
        return DeadLetter::where('dead_letter_id', $id)->first();
    }

    /**
     * 重新投递死信
     */
    public function retryDeadLetter(int $id): bool
    {
        $letter = $this->findDeadLetter($id);
        if (! $letter) {
            return false;
        }

        $subscription = $this->findSubscription((int) $letter->subscription_id);
        if (! $subscription || ! $subscription->is_active) {
            return false;
        }

        $tenantId = $letter->tenant_id !== null ? (string) $letter->tenant_id : null;

        DispatchEventJob::dispatch(
            $letter->event_type,
            $letter->original_data ?? [],
            $tenantId,
            (int) $letter->subscription_id
        );

        $letter->update(['status' => DeadLetter::STATUS_RETRIED]);

        return true;
    }

    /**
     * 标记死信为已解决
     */
    public function resolveDeadLetter(int $id): bool
    {
        $letter = $this->findDeadLetter($id);
        if (! $letter) {
            return false;
        }

        $letter->update(['status' => DeadLetter::STATUS_RESOLVED]);

        return true;
    }

    /**
     * 删除死信
     */
    public function deleteDeadLetter(int $id): bool
    {
        $letter = $this->findDeadLetter($id);
        if (! $letter) {
            return false;
        }

        $letter->delete();

        return true;
    }

    // ----------------------------------------
    // 内部辅助
    // ----------------------------------------

    /**
     * 切换订阅状态
     */
    protected function toggleSubscription(int $id, bool $active): ?EventSubscription
    {
        $subscription = $this->findSubscription($id);
        if (! $subscription) {
            return null;
        }

        $subscription->update(['is_active' => $active]);

        return $subscription->fresh();
    }

    /**
     * 生成随机签名密钥（复用 WebhookService）
     */
    protected function generateSecret(): string
    {
        return $this->webhookService->generateSecret();
    }

    /**
     * 校验事件类型
     */
    protected function assertSupportedEvent(string $eventType): void
    {
        if (! $this->isSupportedEvent($eventType)) {
            throw new \InvalidArgumentException(
                trans('common.event_type_invalid', ['event' => $eventType])
            );
        }
    }

    /**
     * 校验订阅类型
     */
    protected function assertSubscriptionType(string $type): void
    {
        if (! in_array($type, [EventSubscription::TYPE_INTERNAL, EventSubscription::TYPE_WEBHOOK], true)) {
            throw new \InvalidArgumentException(trans('common.event_subscription_type_invalid'));
        }
    }

    /**
     * 校验内部处理器类存在性与接口实现
     */
    protected function assertHandler(string $handler, string $type): void
    {
        if ($type !== EventSubscription::TYPE_INTERNAL) {
            return;
        }

        if (! class_exists($handler)) {
            throw new \InvalidArgumentException(
                trans('common.event_subscription_handler_not_found', ['handler' => $handler])
            );
        }

        if (! is_subclass_of($handler, EventHandler::class)) {
            throw new \InvalidArgumentException(
                trans('common.event_subscription_handler_invalid', ['handler' => $handler])
            );
        }
    }
}
