<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\TenantContextContract;
use MultiTenantSaas\Models\Memory\EntityMemory;
use MultiTenantSaas\Modules\Ai\Services\Agent\EntityMemoryService;
use MultiTenantSaas\Modules\Ai\Services\Agent\MemoryPipeline;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\MemoryModule;

class EntityMemoryServiceTest extends TestCase
{
    protected array $uses = [MemoryModule::class];

    protected EntityMemoryService $service;

    protected MemoryPipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        TenantContext::setTenantId('1001');

        $tenantContext = $this->app->make(TenantContextContract::class);
        $this->service = new EntityMemoryService($tenantContext);
        $this->pipeline = new MemoryPipeline($this->service, $tenantContext);
    }

    // ---- EntityMemoryService ----

    public function test_write_and_read_memory(): void
    {
        $this->service->write('operator', 42, 'language', 'zh-CN');

        $value = $this->service->read('operator', 42, 'language');
        $this->assertSame(['content' => 'zh-CN'], $value);
    }

    public function test_read_nonexistent_returns_null(): void
    {
        $this->assertNull($this->service->read('operator', 999, 'nope'));
    }

    public function test_write_increases_weight_on_update(): void
    {
        $this->service->write('operator', 42, 'pref', 'dark');
        $this->service->write('operator', 42, 'pref', 'light');

        $memory = EntityMemory::where('entity_type', 'operator')
            ->where('entity_id', 42)
            ->where('key', 'pref')
            ->first();

        $this->assertSame(['content' => 'light'], $memory->value);
        $this->assertEqualsWithDelta(1.2, $memory->weight, 0.01);
    }

    public function test_write_array_value_directly(): void
    {
        $this->service->write('operator', 42, 'config', ['theme' => 'dark', 'lang' => 'zh']);

        $value = $this->service->read('operator', 42, 'config');
        $this->assertSame(['theme' => 'dark', 'lang' => 'zh'], $value);
    }

    public function test_recall_returns_top_n_by_weight(): void
    {
        $this->service->write('operator', 42, 'low', 'a');
        $this->service->write('operator', 42, 'high', 'b');
        $this->service->write('operator', 42, 'high', 'b');  // weight 1.2
        $this->service->write('operator', 42, 'high', 'b');  // weight 1.4

        $results = $this->service->recall('operator', 42, 2);

        $this->assertCount(2, $results);
        $this->assertSame('high', $results[0]['key']);
        $this->assertSame('low', $results[1]['key']);
    }

    public function test_compress_removes_low_weight_entries(): void
    {
        // 写入 55 条记忆（超过 MAX_MEMORIES_PER_ENTITY=50）
        for ($i = 0; $i < 55; $i++) {
            EntityMemory::create([
                'tenant_id' => 1001,
                'entity_type' => 'operator',
                'entity_id' => 42,
                'key' => "key_{$i}",
                'value' => ['content' => "val_{$i}"],
                'weight' => $i * 0.1,
                'last_accessed_at' => now(),
            ]);
        }

        $this->assertSame(55, EntityMemory::where('entity_type', 'operator')->where('entity_id', 42)->count());

        $this->service->compress('operator', 42);

        $this->assertSame(50, EntityMemory::where('entity_type', 'operator')->where('entity_id', 42)->count());
    }

    public function test_decay_removes_below_threshold_and_decays_rest(): void
    {
        EntityMemory::create([
            'tenant_id' => 1001, 'entity_type' => 'user', 'entity_id' => 1,
            'key' => 'weak', 'value' => ['content' => 'x'], 'weight' => 0.05, 'last_accessed_at' => now(),
        ]);
        EntityMemory::create([
            'tenant_id' => 1001, 'entity_type' => 'user', 'entity_id' => 1,
            'key' => 'strong', 'value' => ['content' => 'y'], 'weight' => 2.0, 'last_accessed_at' => now(),
        ]);

        $this->service->decay('user', 1, 0.1);

        $this->assertNull(EntityMemory::where('key', 'weak')->first());
        $strong = EntityMemory::where('key', 'strong')->first();
        $this->assertEqualsWithDelta(1.9, $strong->weight, 0.01);
    }

    public function test_tenant_isolation(): void
    {
        Tenant::create(['tenant_id' => 2002, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);

        $this->service->write('operator', 42, 'secret', 'tenant-a-data');

        // 切换租户
        TenantContext::setTenantId('2002');

        $this->assertNull($this->service->read('operator', 42, 'secret'));
    }

    // ---- MemoryPipeline ----

    public function test_inject_returns_formatted_memory_block(): void
    {
        $this->service->write('operator', 42, '偏好:深色主题', ['content' => '深色主题']);
        $this->service->write('operator', 42, '身份:Arthur', ['content' => 'Arthur']);

        $block = $this->pipeline->inject('operator', 42);

        $this->assertStringContainsString('## 用户记忆', $block);
        $this->assertStringContainsString('深色主题', $block);
        $this->assertStringContainsString('Arthur', $block);
    }

    public function test_inject_returns_empty_when_no_memories(): void
    {
        $this->assertSame('', $this->pipeline->inject('operator', 999));
    }

    public function test_extract_captures_preference(): void
    {
        $this->pipeline->extract('operator', 42, '我喜欢用 Vim 键位', '好的，已记住。');

        $memories = EntityMemory::where('entity_type', 'operator')
            ->where('entity_id', 42)
            ->get();

        $this->assertTrue($memories->count() >= 1);
        $this->assertTrue($memories->contains(fn ($m) => str_contains($m->key, '偏好')));
    }

    public function test_extract_captures_identity(): void
    {
        $this->pipeline->extract('operator', 42, '我的名字是 Arthur', '你好 Arthur！');

        $memory = EntityMemory::where('entity_type', 'operator')
            ->where('entity_id', 42)
            ->where('key', 'like', '%身份%')
            ->first();

        $this->assertNotNull($memory);
        $this->assertStringContainsString('Arthur', $memory->value['content']);
    }

    public function test_extract_ignores_irrelevant_messages(): void
    {
        $this->pipeline->extract('operator', 42, '帮我查一下订单', '好的，正在查询。');

        $count = EntityMemory::where('entity_type', 'operator')
            ->where('entity_id', 42)
            ->count();

        $this->assertSame(0, $count);
    }
}
