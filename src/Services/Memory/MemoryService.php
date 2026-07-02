<?php

declare(strict_types=1);

namespace MultiTenantSaas\Services\Memory;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Memory\EntityMemory;
use MultiTenantSaas\Models\Memory\TenantMemory;

class MemoryService implements MemoryServiceContract
{
    private float $decayRate;
    private int $compressThreshold;

    public function __construct(
        float $decayRate = 0.95,
        int $compressThreshold = 100,
    ) {
        $this->decayRate = $decayRate;
        $this->compressThreshold = $compressThreshold;
    }

    public function read(string $entityType, int $entityId, string $key): mixed
    {
        $memory = $this->findMemory($entityType, $entityId, $key);

        if ($memory === null) {
            return null;
        }

        $memory->update(['last_accessed_at' => now()]);

        return $memory->value;
    }

    public function write(string $entityType, int $entityId, string $key, mixed $value): void
    {
        $memory = $this->findMemory($entityType, $entityId, $key);

        if ($memory !== null) {
            $memory->update([
                'value' => $value,
                'weight' => $memory->weight + 0.1,
                'last_accessed_at' => now(),
            ]);
        } else {
            EntityMemory::create([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'key' => $key,
                'value' => $value,
                'weight' => 1.0,
                'last_accessed_at' => now(),
            ]);
        }
    }

    public function compress(string $entityType, int $entityId): void
    {
        $memories = EntityMemory::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderBy('weight', 'desc')
            ->get();

        if ($memories->count() <= $this->compressThreshold) {
            return;
        }

        $toCompress = $memories->slice($this->compressThreshold);
        $compressed = $this->mergeMemories($toCompress->toArray());

        foreach ($toCompress as $memory) {
            $memory->delete();
        }

        if (!empty($compressed)) {
            EntityMemory::create([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'key' => '_compressed_' . now()->timestamp,
                'value' => $compressed,
                'weight' => 0.5,
                'last_accessed_at' => now(),
            ]);
        }
    }

    public function decay(float $threshold = 0.1): void
    {
        EntityMemory::where('weight', '<=', $threshold)->delete();

        EntityMemory::where('weight', '>', $threshold)
            ->update(['weight' => \DB::raw("weight * {$this->decayRate}")]);

        TenantMemory::where('weight', '<=', $threshold)->delete();

        TenantMemory::where('weight', '>', $threshold)
            ->update(['weight' => \DB::raw("weight * {$this->decayRate}")]);
    }

    public function readTenantMemory(int $tenantId, string $type, string $key): mixed
    {
        $memory = TenantMemory::where('tenant_id', $tenantId)
            ->where('type', $type)
            ->where('key', $key)
            ->first();

        if ($memory === null) {
            return null;
        }

        $memory->update(['last_accessed_at' => now()]);

        return $memory->value;
    }

    public function writeTenantMemory(int $tenantId, string $type, string $key, mixed $value): void
    {
        $memory = TenantMemory::where('tenant_id', $tenantId)
            ->where('type', $type)
            ->where('key', $key)
            ->first();

        if ($memory !== null) {
            $memory->update([
                'value' => $value,
                'weight' => $memory->weight + 0.1,
                'last_accessed_at' => now(),
            ]);
        } else {
            TenantMemory::create([
                'tenant_id' => $tenantId,
                'type' => $type,
                'key' => $key,
                'value' => $value,
                'weight' => 1.0,
                'last_accessed_at' => now(),
            ]);
        }
    }

    public function getDecayRate(): float
    {
        return $this->decayRate;
    }

    public function getCompressThreshold(): int
    {
        return $this->compressThreshold;
    }

    protected function findMemory(string $entityType, int $entityId, string $key): ?EntityMemory
    {
        return EntityMemory::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('key', $key)
            ->first();
    }

    protected function getTenantId(): int
    {
        return (int) TenantContext::getId();
    }

    protected function mergeMemories(array $memories): array
    {
        $merged = [];

        foreach ($memories as $memory) {
            $merged[] = [
                'key' => $memory['key'] ?? '',
                'value' => $memory['value'] ?? null,
                'weight' => $memory['weight'] ?? 0,
            ];
        }

        return $merged;
    }
}
