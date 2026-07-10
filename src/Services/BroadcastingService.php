<?php

namespace MultiTenantSaas\Services;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\BroadcastEvent;

/**
 * 实时广播服务
 *
 * 基于 Laravel 广播（Reverb / Pusher / Soketi）实现实时推送，支持：
 *  - 租户级频道订阅（private-tenant.{tenantId}）
 *  - 用户级频道订阅（private-tenant.{tenantId}.{userId}）
 *  - AI 视频生成完成通知
 *  - 系统公告实时推送
 *  - 在线状态广播
 *
 * 所有广播事件均写入 broadcast_events 表用于审计与重试。
 * 当广播驱动不可用时（未配置 Reverb/Pusher），自动降级为仅记录，
 * 客户端可通过轮询查询 broadcast_events 或 in_app_notifications 获取数据。
 */
class BroadcastingService
{
    /** 频道前缀 */
    public const CHANNEL_PREFIX = 'tenant';

    /**
     * 构造租户级频道名（客户端订阅 private-tenant.{tenantId}）
     */
    public function tenantChannel(int $tenantId): string
    {
        return self::CHANNEL_PREFIX . '.' . $tenantId;
    }

    /**
     * 构造用户级频道名（客户端订阅 private-tenant.{tenantId}.{userId}）
     */
    public function userChannel(int $tenantId, int $userId): string
    {
        return self::CHANNEL_PREFIX . '.' . $tenantId . '.' . $userId;
    }

    /**
     * 广播是否可用（已配置广播驱动且非 null）
     */
    public function isAvailable(): bool
    {
        try {
            $driver = config('broadcasting.default', 'null');

            return $driver !== 'null' && $driver !== null && $driver !== '';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 向租户频道广播事件
     *
     * @param  int  $tenantId  租户 ID
     * @param  string  $eventType  事件类型
     * @param  array<string,mixed>  $payload  负载数据
     */
    public function broadcastToTenant(int $tenantId, string $eventType, array $payload = []): BroadcastEvent
    {
        $channel = $this->tenantChannel($tenantId);
        $payload['event'] = $eventType;

        return $this->dispatch(BroadcastEvent::EVENT_TENANT_BROADCAST, $channel, $payload, $tenantId, $eventType);
    }

    /**
     * 向用户频道广播事件
     *
     * @param  int  $tenantId  租户 ID
     * @param  int  $userId  用户 ID
     * @param  string  $eventType  事件类型
     * @param  array<string,mixed>  $payload  负载数据
     */
    public function broadcastToUser(int $tenantId, int $userId, string $eventType, array $payload = []): BroadcastEvent
    {
        $channel = $this->userChannel($tenantId, $userId);
        $payload['event'] = $eventType;
        $payload['user_id'] = $userId;

        return $this->dispatch($eventType, $channel, $payload, $tenantId, $eventType);
    }

    /**
     * AI 视频生成完成通知
     *
     * @param  int  $tenantId  租户 ID
     * @param  int  $userId  触发用户 ID
     * @param  array<string,mixed>  $videoData  视频信息（task_id / url / duration 等）
     */
    public function broadcastAiVideoComplete(int $tenantId, int $userId, array $videoData): BroadcastEvent
    {
        $channel = $this->userChannel($tenantId, $userId);
        $payload = array_merge([
            'event' => BroadcastEvent::EVENT_AI_VIDEO_COMPLETED,
            'user_id' => $userId,
            'message' => trans('notification.ai_video_completed_body'),
        ], $videoData);

        return $this->dispatch(BroadcastEvent::EVENT_AI_VIDEO_COMPLETED, $channel, $payload, $tenantId);
    }

    /**
     * 系统公告实时推送
     *
     * @param  int  $tenantId  租户 ID
     * @param  string  $message  公告内容
     * @param  array<string,mixed>  $extra  附加信息（级别 / 链接等）
     */
    public function broadcastSystemAnnouncement(int $tenantId, string $message, array $extra = []): BroadcastEvent
    {
        $channel = $this->tenantChannel($tenantId);
        $payload = array_merge([
            'event' => BroadcastEvent::EVENT_SYSTEM_ANNOUNCEMENT,
            'message' => $message,
            'level' => 'info',
            'timestamp' => now()->toIso8601String(),
        ], $extra);

        return $this->dispatch(BroadcastEvent::EVENT_SYSTEM_ANNOUNCEMENT, $channel, $payload, $tenantId);
    }

    /**
     * 在线状态广播
     *
     * @param  int  $tenantId  租户 ID
     * @param  int  $userId  用户 ID
     * @param  bool  $online  是否在线
     */
    public function broadcastOnlineStatus(int $tenantId, int $userId, bool $online): BroadcastEvent
    {
        $channel = $this->tenantChannel($tenantId);
        $payload = [
            'event' => BroadcastEvent::EVENT_ONLINE_STATUS,
            'user_id' => $userId,
            'online' => $online,
            'timestamp' => now()->toIso8601String(),
        ];

        return $this->dispatch(BroadcastEvent::EVENT_ONLINE_STATUS, $channel, $payload, $tenantId);
    }

    /**
     * 重试未发送的广播事件
     *
     * @return int 重试成功的事件数
     */
    public function retryPending(): int
    {
        $pending = BroadcastEvent::pending()->limit(100)->get();
        $succeeded = 0;

        foreach ($pending as $event) {
            $ok = $this->sendToBroadcaster($event->channel, $event->event_type, $event->payload ?? []);

            $event->update([
                'is_sent' => $ok,
                'sent_at' => $ok ? now() : null,
                'error_message' => $ok ? null : trans('notification.broadcast_failed'),
            ]);

            if ($ok) {
                $succeeded++;
            }
        }

        return $succeeded;
    }

    /**
     * 查询当前租户广播事件历史（受 TenantScope 自动隔离）
     *
     * @return Collection<int,BroadcastEvent>
     */
    public function getHistory(?string $eventType = null, int $limit = 100): Collection
    {
        $limit = min(max($limit, 1), 500);

        $query = BroadcastEvent::query()->orderByDesc('created_at')->limit($limit);

        if ($eventType !== null) {
            $query->ofType($eventType);
        }

        return $query->get();
    }

    /**
     * 派发广播事件（记录 + 发送）
     *
     * @param  string  $eventType  存储的事件类型
     * @param  string  $channel  频道名（不含 private- 前缀）
     * @param  array<string,mixed>  $payload  负载
     * @param  int|null  $tenantId  租户 ID
     * @param  string|null  $broadcastAs  客户端接收的事件名（默认同 eventType）
     */
    protected function dispatch(string $eventType, string $channel, array $payload, ?int $tenantId = null, ?string $broadcastAs = null): BroadcastEvent
    {
        // 解析租户 ID：优先使用参数，其次从上下文获取
        if ($tenantId === null) {
            $resolved = TenantContext::getId();
            $tenantId = $resolved !== null ? (int) $resolved : null;
        }

        // 先记录事件（is_sent 默认 false）
        $record = BroadcastEvent::create([
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'channel' => 'private-' . $channel,
            'payload' => $payload,
            'is_sent' => false,
        ]);

        // 尝试发送
        $eventName = $broadcastAs ?? $eventType;
        $sent = $this->sendToBroadcaster($channel, $eventName, $payload);

        $record->update([
            'is_sent' => $sent,
            'sent_at' => $sent ? now() : null,
            'error_message' => $sent ? null : trans('notification.broadcast_failed'),
        ]);

        return $record;
    }

    /**
     * 调用广播驱动发送事件（不可用时降级为 false，不抛异常）
     *
     * @param  string  $channel  频道名（不含 private- 前缀）
     * @param  string  $eventName  客户端接收的事件名
     * @param  array<string,mixed>  $payload  负载
     */
    protected function sendToBroadcaster(string $channel, string $eventName, array $payload): bool
    {
        if (! $this->isAvailable()) {
            Log::debug('[BroadcastingService] 广播驱动不可用，降级为仅记录', [
                'channel' => $channel,
                'event' => $eventName,
            ]);

            return false;
        }

        try {
            Broadcast::broadcast([new PrivateChannel($channel)], $eventName, $payload);

            return true;
        } catch (\Throwable $e) {
            Log::debug('[BroadcastingService] 广播发送失败', [
                'channel' => $channel,
                'event' => $eventName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
