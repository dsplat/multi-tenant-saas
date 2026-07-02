<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Memory\EntityMemory;
use MultiTenantSaas\Models\Memory\TenantMemory;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\Memory\MemoryService;
use MultiTenantSaas\Tests\Schema\MemoryModule;

class MemoryServiceTest extends TestCase
{
    protected array $uses = [MemoryModule::class];

    private MemoryService $memoryService;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->memoryService = new MemoryService(0.95, 5);

        $this->tenant = Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        TenantContext::setTenantId((string) $this->tenant->tenant_id);
    }

    public function test_read_returns_null_for_nonexistent(): void
    {
        $result = $this->memoryService->read('agent', 100, 'nonexistent_key');

        $this->assertNull($result);
    }

    public function test_write_and_read(): void
    {
        $this->memoryService->write('agent', 100, 'preference', ['theme' => 'dark']);

        $result = $this->memoryService->read('agent', 100, 'preference');

        $this->assertSame(['theme' => 'dark'], $result);
    }

    public function test_write_updates_existing(): void
    {
        $this->memoryService->write('agent', 100, 'counter', 1);
        $this->memoryService->write('agent', 100, 'counter', 2);

        $result = $this->memoryService->read('agent', 100, 'counter');

        $this->assertSame(2, $result);
    }

    public function test_write_increases_weight(): void
    {
        $this->memoryService->write('agent', 100, 'key', 'value1');
        $this->memoryService->write('agent', 100, 'key', 'value2');

        $memory = EntityMemory::where('entity_type', 'agent')
            ->where('entity_id', 100)
            ->where('key', 'key')
            ->first();

        $this->assertGreaterThan(1.0, $memory->weight);
    }

    public function test_read_updates_last_accessed(): void
    {
        $this->memoryService->write('agent', 100, 'key', 'value');

        $memory = EntityMemory::where('entity_type', 'agent')
            ->where('entity_id', 100)
            ->where('key', 'key')
            ->first();

        $lastAccessed = $memory->last_accessed_at;

        $this->memoryService->read('agent', 100, 'key');

        $memory->refresh();
        $this->assertNotNull($memory->last_accessed_at);
    }

    public function test_compress_below_threshold(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->memoryService->write('agent', 200, "key_{$i}", "value_{$i}");
        }

        $this->memoryService->compress('agent', 200);

        $count = EntityMemory::where('entity_type', 'agent')
            ->where('entity_id', 200)
            ->count();

        $this->assertSame(3, $count);
    }

    public function test_compress_above_threshold(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->memoryService->write('agent', 300, "key_{$i}", "value_{$i}");
        }

        $this->memoryService->compress('agent', 300);

        $count = EntityMemory::where('entity_type', 'agent')
            ->where('entity_id', 300)
            ->count();

        $this->assertLessThanOrEqual(6, $count);
    }

    public function test_decay_removes_low_weight(): void
    {
        EntityMemory::create([
            'tenant_id' => $this->tenant->tenant_id,
            'entity_type' => 'agent',
            'entity_id' => 400,
            'key' => 'low_weight',
            'value' => 'data',
            'weight' => 0.05,
        ]);

        $this->memoryService->decay(0.1);

        $exists = EntityMemory::where('entity_type', 'agent')
            ->where('entity_id', 400)
            ->where('key', 'low_weight')
            ->exists();

        $this->assertFalse($exists);
    }

    public function test_decay_reduces_weight(): void
    {
        EntityMemory::create([
            'tenant_id' => $this->tenant->tenant_id,
            'entity_type' => 'agent',
            'entity_id' => 500,
            'key' => 'high_weight',
            'value' => 'data',
            'weight' => 1.0,
        ]);

        $this->memoryService->decay(0.1);

        $memory = EntityMemory::where('entity_type', 'agent')
            ->where('entity_id', 500)
            ->where('key', 'high_weight')
            ->first();

        $this->assertLessThan(1.0, $memory->weight);
        $this->assertGreaterThan(0.0, $memory->weight);
    }

    public function test_tenant_memory_read_write(): void
    {
        $this->memoryService->writeTenantMemory(1001, 'config', 'theme', 'dark');

        $result = $this->memoryService->readTenantMemory(1001, 'config', 'theme');

        $this->assertSame('dark', $result);
    }

    public function test_tenant_memory_read_null(): void
    {
        $result = $this->memoryService->readTenantMemory(1001, 'config', 'nonexistent');

        $this->assertNull($result);
    }

    public function test_tenant_memory_update(): void
    {
        $this->memoryService->writeTenantMemory(1001, 'config', 'theme', 'dark');
        $this->memoryService->writeTenantMemory(1001, 'config', 'theme', 'light');

        $result = $this->memoryService->readTenantMemory(1001, 'config', 'theme');

        $this->assertSame('light', $result);
    }

    public function test_get_decay_rate(): void
    {
        $this->assertSame(0.95, $this->memoryService->getDecayRate());
    }

    public function test_get_compress_threshold(): void
    {
        $this->assertSame(5, $this->memoryService->getCompressThreshold());
    }

    public function test_different_entities_isolated(): void
    {
        $this->memoryService->write('agent', 100, 'key', 'agent_value');
        $this->memoryService->write('user', 100, 'key', 'user_value');

        $agentValue = $this->memoryService->read('agent', 100, 'key');
        $userValue = $this->memoryService->read('user', 100, 'key');

        $this->assertSame('agent_value', $agentValue);
        $this->assertSame('user_value', $userValue);
    }
}
