<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Live\Services;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Course\Models\CourseChapter;
use MultiTenantSaas\Modules\Course\Models\CourseEntitlement;
use MultiTenantSaas\Modules\Course\Services\CourseService;
use MultiTenantSaas\Modules\Live\Contracts\LiveProviderContract;
use MultiTenantSaas\Modules\Live\Events\LiveEnded;
use MultiTenantSaas\Modules\Live\Events\LiveStarted;
use MultiTenantSaas\Modules\Live\Models\LiveChatMessage;
use MultiTenantSaas\Modules\Live\Models\LiveRoom;
use MultiTenantSaas\Modules\Live\Models\LiveViewRecord;
use MultiTenantSaas\Modules\Live\Providers\ManualProvider;
use MultiTenantSaas\Modules\Live\Providers\PolyvProvider;
use MultiTenantSaas\Modules\Live\Providers\TencentProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 直播间服务（生命周期 + 观看权限 + 回放转化 + 观看记录 + 弹幕落库）
 *
 * 设计要点：
 * - 供给方走 LiveProviderContract 适配器，按房间 provider 惰性构造并注入租户/平台凭证
 * - 挂课程（course_id）即复用 course_entitlements 观看权益
 * - 回放转化为挂载课程的视频章节，学习进度/记录全链路复用
 */
class LiveRoomService
{
    public function __construct(
        private readonly CourseService $courseService,
        private readonly LiveCredentialService $credentials,
    ) {}

    // ========== 房间生命周期 ==========

    public function create(int $tenantId, array $data): LiveRoom
    {
        TenantContext::setTenantId((string) $tenantId);

        $providerName = (string) ($data['provider'] ?? LiveRoom::PROVIDER_MANUAL);

        // 按名称构造（polyv/tencent 构造即解析凭证，缺失在此抛出）
        $provider = $this->providerByName($providerName, $tenantId);

        $providerInfo = $provider->createRoom($data);

        return LiveRoom::create([
            'tenant_id' => $tenantId,
            'title' => $data['title'],
            'cover' => $data['cover'] ?? null,
            'course_id' => isset($data['course_id']) ? (int) $data['course_id'] : null,
            'provider' => $providerName,
            'provider_room_id' => $providerInfo['provider_room_id'] ?? null,
            'config' => $providerInfo['config'] ?? null,
            'status' => LiveRoom::STATUS_SCHEDULED,
            'scheduled_at' => $data['scheduled_at'] ?? null,
        ]);
    }

    public function startLive(int $tenantId, int $roomId): LiveRoom
    {
        TenantContext::setTenantId((string) $tenantId);
        $room = $this->find($tenantId, $roomId);

        if ($room->status !== LiveRoom::STATUS_SCHEDULED) {
            throw new UnprocessableEntityHttpException('Room is not in scheduled status');
        }

        // 非手填供给方先验凭证（避免开播后推拉流地址取不出）
        if ($room->provider !== LiveRoom::PROVIDER_MANUAL) {
            $this->providerFor($room);
        }

        $room->update([
            'status' => LiveRoom::STATUS_LIVING,
            'started_at' => now(),
        ]);

        LiveStarted::dispatch($tenantId, (int) $room->room_id, (string) $room->title);

        return $room->fresh();
    }

    /**
     * 结束直播（replay_url 可在此处补填或后续单独更新）
     */
    public function endLive(int $tenantId, int $roomId, ?string $replayUrl = null): LiveRoom
    {
        TenantContext::setTenantId((string) $tenantId);
        $room = $this->find($tenantId, $roomId);

        if ($room->status !== LiveRoom::STATUS_LIVING) {
            throw new UnprocessableEntityHttpException('Room is not living');
        }

        $room->update([
            'status' => LiveRoom::STATUS_ENDED,
            'ended_at' => now(),
            'replay_url' => $replayUrl ?? $room->replay_url,
        ]);

        $room = $room->fresh();
        LiveEnded::dispatch($tenantId, (int) $room->room_id, (string) $room->title, $room->replay_url);

        return $room;
    }

    public function update(int $tenantId, int $roomId, array $data): LiveRoom
    {
        TenantContext::setTenantId((string) $tenantId);
        $room = $this->find($tenantId, $roomId);

        $fillable = array_intersect_key($data, array_flip([
            'title', 'cover', 'course_id', 'config', 'scheduled_at', 'replay_url',
        ]));

        $room->update($fillable);

        return $room->fresh();
    }

    public function getList(int $tenantId, array $filters = []): array
    {
        TenantContext::setTenantId((string) $tenantId);

        $query = LiveRoom::where('tenant_id', $tenantId);
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['course_id'])) {
            $query->where('course_id', (int) $filters['course_id']);
        }

        return $query->orderByDesc('created_at')->get()->all();
    }

    /**
     * 播放地址（经供给方适配器解析）
     */
    public function getStreamUrls(int $tenantId, int $roomId): array
    {
        TenantContext::setTenantId((string) $tenantId);
        $room = $this->find($tenantId, $roomId);

        return $this->providerFor($room)->getStreamUrls($room);
    }

    /**
     * 聊天室连接参数（经供给方适配器解析，不支持返回 null）
     */
    public function chatConfig(int $tenantId, int $roomId): ?array
    {
        TenantContext::setTenantId((string) $tenantId);
        $room = $this->find($tenantId, $roomId);

        return $this->providerFor($room)->chatConfig($room);
    }

    // ========== 观看权限 ==========

    /**
     * 是否可观看：挂课程需持有有效权益，未挂课程公开
     */
    public function canWatch(int $tenantId, int $roomId, int $userId): bool
    {
        TenantContext::setTenantId((string) $tenantId);
        $room = $this->find($tenantId, $roomId);

        if ($room->course_id === null) {
            return true;
        }

        $entitlement = CourseEntitlement::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('course_id', $room->course_id)
            ->first();

        return $entitlement !== null && $entitlement->isActive();
    }

    // ========== 回放转化 ==========

    /**
     * 回放发布为挂载课程的视频章节（回放即课程资产）
     *
     * 幂等：同一房间重复发布直接返回已有章节
     */
    public function publishReplay(int $tenantId, int $roomId, ?string $replayUrl = null): CourseChapter
    {
        TenantContext::setTenantId((string) $tenantId);
        $room = $this->find($tenantId, $roomId);

        if ($room->course_id === null) {
            throw new UnprocessableEntityHttpException('Room has no mounted course');
        }

        $url = $replayUrl ?? $room->replay_url;
        if (empty($url)) {
            throw new UnprocessableEntityHttpException('No replay url available');
        }

        if ($url !== $room->replay_url) {
            $room->update(['replay_url' => $url]);
        }

        // 幂等：已存在同名回放章节直接返回
        $existing = CourseChapter::where('tenant_id', $tenantId)
            ->where('course_id', $room->course_id)
            ->where('title', $room->title . '（直播回放）')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $maxSort = CourseChapter::where('tenant_id', $tenantId)
            ->where('course_id', $room->course_id)
            ->max('sort_order') ?? 0;

        return $this->courseService->addChapter($tenantId, (int) $room->course_id, [
            'title' => $room->title . '（直播回放）',
            'type' => 'video',
            'file_url' => $url,
            'sort_order' => $maxSort + 1,
        ]);
    }

    // ========== 观看记录 ==========

    /**
     * 观看时长上报（(room, user) 幂等：时长累计，首末观看时间维护）
     */
    public function reportView(int $tenantId, int $roomId, int $userId, int $durationSeconds): LiveViewRecord
    {
        TenantContext::setTenantId((string) $tenantId);
        $this->find($tenantId, $roomId);

        $record = LiveViewRecord::where('tenant_id', $tenantId)
            ->where('room_id', $roomId)
            ->where('user_id', $userId)
            ->first();

        if ($record === null) {
            return LiveViewRecord::create([
                'tenant_id' => $tenantId,
                'room_id' => $roomId,
                'user_id' => $userId,
                'duration_seconds' => max(0, $durationSeconds),
                'first_view_at' => now(),
                'last_view_at' => now(),
            ]);
        }

        $record->update([
            'duration_seconds' => $record->duration_seconds + max(0, $durationSeconds),
            'last_view_at' => now(),
        ]);

        return $record->fresh();
    }

    /**
     * 学员观看记录列表（学习画像聚合用）
     *
     * @return LiveViewRecord[]
     */
    public function viewRecordsForUser(int $tenantId, int $userId): array
    {
        TenantContext::setTenantId((string) $tenantId);

        return LiveViewRecord::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->orderByDesc('last_view_at')
            ->get()
            ->all();
    }

    // ========== 弹幕记录 ==========

    /**
     * 聊天消息落库（provider_msg_id 幂等去重；manual/腾讯无回调则无数据）
     */
    public function recordChatMessage(int $tenantId, int $roomId, array $payload): LiveChatMessage
    {
        TenantContext::setTenantId((string) $tenantId);
        $this->find($tenantId, $roomId);

        $msgId = $payload['provider_msg_id'] ?? null;
        if ($msgId !== null) {
            $existing = LiveChatMessage::where('tenant_id', $tenantId)
                ->where('provider_msg_id', $msgId)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        return LiveChatMessage::create([
            'tenant_id' => $tenantId,
            'room_id' => $roomId,
            'provider_msg_id' => $msgId,
            'user_id' => isset($payload['user_id']) ? (int) $payload['user_id'] : null,
            'nick' => $payload['nick'] ?? null,
            'content' => $payload['content'],
            'sent_at' => $payload['sent_at'] ?? now(),
            'raw' => $payload['raw'] ?? null,
        ]);
    }

    /**
     * 房间聊天记录（管理端审计/回放侧栏）
     *
     * @return LiveChatMessage[]
     */
    public function chatMessages(int $tenantId, int $roomId, int $limit = 200): array
    {
        TenantContext::setTenantId((string) $tenantId);
        $this->find($tenantId, $roomId);

        return LiveChatMessage::where('tenant_id', $tenantId)
            ->where('room_id', $roomId)
            ->orderBy('sent_at')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function find(int $tenantId, int $roomId): LiveRoom
    {
        return LiveRoom::where('tenant_id', $tenantId)
            ->where('room_id', $roomId)
            ->first() ?? throw new NotFoundHttpException('Live room not found');
    }

    private function providerFor(LiveRoom $room): LiveProviderContract
    {
        return $this->providerByName((string) $room->provider, (int) $room->tenant_id);
    }

    /**
     * 按名称构造供给方（manual 无凭证；polyv/tencent 构造即解析租户/平台凭证）
     */
    private function providerByName(string $name, int $tenantId): LiveProviderContract
    {
        return match ($name) {
            LiveRoom::PROVIDER_MANUAL => new ManualProvider(),
            LiveRoom::PROVIDER_POLYV => new PolyvProvider($this->credentials->for('polyv', $tenantId)),
            LiveRoom::PROVIDER_TENCENT => new TencentProvider($this->credentials->for('tencent', $tenantId)),
            default => throw new UnprocessableEntityHttpException("Unknown live provider: {$name}"),
        };
    }
}
