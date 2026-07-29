<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Agent;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\MemoryContract;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Models\Memory\EntityMemory;

/**
 * 实体记忆服务 — MemoryContract 的生产实现
 *
 * 管理实体级别（用户/客户/Agent 等）的结构化记忆：
 * - read/write：单条记忆读写，写时自动增权
 * - recall：按权重降序检索 Top-N 记忆（供上下文注入）
 * - compress：超阈值时保留高权重条目，其余合并为摘要
 * - decay：周期性衰减权重，清理低权重记忆
 *
 * 租户隔离由 EntityMemory 模型的 BelongsToTenant 全局作用域保障。
 */
class EntityMemoryService implements MemoryContract
{
    /** 每个实体默认保留的最大记忆条数 */
    private const MAX_MEMORIES_PER_ENTITY = 50;

    /** 写入时权重增量 */
    private const WRITE_WEIGHT_INCREMENT = 0.2;

    /** 新建记忆的初始权重 */
    private const INITIAL_WEIGHT = 1.0;

    /** 每次衰减的乘数 */
    private const DECAY_FACTOR = 0.95;

    public function __construct(
        private TenantContextContract $tenantContext,
    ) {}

    /**
     * 读取实体记忆
     */
    public function read(string $entityType, int $entityId, string $key): mixed
    {
        $memory = $this->query($entityType, $entityId)
            ->where('key', $key)
            ->first();

        if ($memory === null) {
            return null;
        }

        // 更新访问时间（用于未来 LRU 策略）
        $memory->update(['last_accessed_at' => now()]);

        return $memory->value;
    }

    /**
     * 写入实体记忆（upsert + 增权）
     */
    public function write(string $entityType, int $entityId, string $key, mixed $value): void
    {
        $memory = $this->query($entityType, $entityId)
            ->where('key', $key)
            ->first();

        if ($memory !== null) {
            $memory->update([
                'value' => is_array($value) ? $value : ['content' => $value],
                'weight' => min($memory->weight + self::WRITE_WEIGHT_INCREMENT, 10.0),
                'last_accessed_at' => now(),
            ]);
        } else {
            EntityMemory::create([
                'tenant_id' => $this->resolveTenantId(),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'key' => $key,
                'value' => is_array($value) ? $value : ['content' => $value],
                'weight' => self::INITIAL_WEIGHT,
                'last_accessed_at' => now(),
            ]);
        }
    }

    /**
     * 检索实体的高权重记忆（供上下文注入）
     *
     * 非 Contract 方法 — 管线专用。按权重降序返回 Top-N 记忆的 key→value 映射。
     *
     * @return list<array{key: string, value: mixed, weight: float}>
     */
    public function recall(string $entityType, int $entityId, int $limit = 10): array
    {
        return $this->query($entityType, $entityId)
            ->orderByDesc('weight')
            ->orderByDesc('last_accessed_at')
            ->limit($limit)
            ->get()
            ->map(fn ($m) => [
                'key' => $m->key,
                'value' => $m->value,
                'weight' => $m->weight,
            ])
            ->toArray();
    }

    /**
     * 压缩实体记忆
     *
     * 超过 MAX_MEMORIES_PER_ENTITY 时，保留权重最高的 N 条，删除其余。
     * （v1 简化：直接删除低权重条目；v2 可用 AI 合并为摘要）
     */
    public function compress(string $entityType, int $entityId): void
    {
        $count = $this->query($entityType, $entityId)->count();

        if ($count <= self::MAX_MEMORIES_PER_ENTITY) {
            return;
        }

        // 保留权重最高的 N 条，删除其余
        $keepIds = $this->query($entityType, $entityId)
            ->orderByDesc('weight')
            ->orderByDesc('last_accessed_at')
            ->limit(self::MAX_MEMORIES_PER_ENTITY)
            ->pluck('memory_id');

        $this->query($entityType, $entityId)
            ->whereNotIn('memory_id', $keepIds)
            ->delete();

        Log::debug('EntityMemoryService: 压缩完成', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $count,
            'after' => self::MAX_MEMORIES_PER_ENTITY,
        ]);
    }

    /**
     * 衰减实体记忆权重
     *
     * 删除权重低于阈值的记忆，对其余执行 weight *= DECAY_FACTOR。
     */
    public function decay(string $entityType, int $entityId, float $threshold = 0.1): void
    {
        // 删除低权重
        $this->query($entityType, $entityId)
            ->where('weight', '<', $threshold)
            ->delete();

        // 衰减其余
        $this->query($entityType, $entityId)
            ->update(['weight' => \DB::raw('weight * ' . self::DECAY_FACTOR)]);
    }

    // ---- 内部 ----

    private function query(string $entityType, int $entityId)
    {
        return EntityMemory::where('entity_type', $entityType)
            ->where('entity_id', $entityId);
    }

    private function resolveTenantId(): int
    {
        return (int) $this->tenantContext->resolveId();
    }
}
