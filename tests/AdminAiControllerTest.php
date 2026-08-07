<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Ai\Models\AiModelAlias;
use MultiTenantSaas\Modules\Ai\Models\AiProvider;
use MultiTenantSaas\Modules\Ai\Models\AiTenantConfig;
use MultiTenantSaas\Modules\Ai\Services\AiPlatformConfigService;
use MultiTenantSaas\Modules\Infrastructure\Models\SystemSetting;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Scopes\TenantScope;
use MultiTenantSaas\Tests\Schema\AiModule;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\InfrastructureModule;
use MultiTenantSaas\Tests\Schema\RbacModule;

/**
 * Admin AI 配置后台接口测试
 *
 * 覆盖：模型别名 CRUD、平台默认模型组（DB 覆盖层）、租户 AI 配置 upsert、
 * 模型目录同步（Http::fake）、提供商多源管理 CRUD（api_key 掩码安全）、provider 连接测试。
 */
class AdminAiControllerTest extends TestCase
{
    protected array $uses = [CoreModule::class, RbacModule::class, InfrastructureModule::class, AiModule::class];

    private int $tenantId = 9001;

    private User $admin;

    private string $token = '';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // 与生产一致：sanctum guard 不绑定 provider，Operator/User token 均可认证
        $app['config']->set('auth.guards.sanctum.provider', null);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
        ]);

        $this->admin = User::create([
            'user_id' => 9001,
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'tenant_id' => $this->tenantId,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        // admin 接口需 platform scope Operator（RBAC 直通），且需 operator_tenants 记录过租户校验
        $operator = Operator::create([
            'email' => 'admin@test.com',
            'name' => 'Admin',
            'scope' => 'platform',
            'is_active' => true,
        ]);

        $tenantAdminRoleId = DB::table('roles')
            ->where('name', 'tenant_admin')
            ->whereNull('tenant_id')
            ->value('role_id');

        OperatorTenant::create([
            'operator_id' => $operator->operator_id,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->admin->user_id,
            'role' => 'tenant_admin',
            'role_id' => $tenantAdminRoleId,
            'is_active' => true,
            'accepted_at' => now(),
        ]);

        DB::table('tenant_users')->insert([
            'tenant_id' => $this->tenantId,
            'user_id' => $this->admin->user_id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        TenantContext::setTenantId($this->tenantId);
        $this->token = $operator->createToken('test')->plainTextToken;
    }

    private function auth(): static
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    // ==================================================================
    // 模型别名 CRUD
    // ==================================================================

    public function test_alias_crud_lifecycle(): void
    {
        // 创建
        $create = $this->auth()->postJson('/api/v1/admin/ai/aliases', [
            'alias' => 'text-embedding-3-small',
            'actual_model' => 'qwen3.7-text-embedding',
            'provider' => 'bailian',
            'type' => 'text',
        ]);
        $create->assertCreated()->assertJsonPath('data.alias', 'text-embedding-3-small');
        $aliasId = $create->json('data.alias_id');

        // 列表
        $this->auth()->getJson('/api/v1/admin/ai/aliases')
            ->assertSuccessful()
            ->assertJsonPath('data.0.alias', 'text-embedding-3-small');

        // 更新
        $this->auth()->putJson("/api/v1/admin/ai/aliases/{$aliasId}", [
            'alias' => 'text-embedding-3-small',
            'actual_model' => 'qwen3.8-text-embedding',
            'type' => 'text',
            'is_active' => false,
        ])->assertSuccessful()->assertJsonPath('data.actual_model', 'qwen3.8-text-embedding');

        // 删除
        $this->auth()->deleteJson("/api/v1/admin/ai/aliases/{$aliasId}")->assertSuccessful();
        $this->assertNull(AiModelAlias::find($aliasId));
    }

    public function test_alias_store_rejects_duplicate_alias(): void
    {
        AiModelAlias::create(['alias' => 'dup-alias', 'actual_model' => 'm1', 'type' => 'text']);

        $this->auth()->postJson('/api/v1/admin/ai/aliases', [
            'alias' => 'dup-alias',
            'actual_model' => 'm2',
            'type' => 'text',
        ])->assertStatus(422);
    }

    // ==================================================================
    // 平台默认模型组（DB 覆盖层）
    // ==================================================================

    public function test_defaults_update_overrides_and_empty_value_removes(): void
    {
        config(['ai.text.default_chat_model' => 'env-chat-model']);

        // DB 覆盖生效
        $this->auth()->putJson('/api/v1/admin/ai/defaults', [
            'default_chat_model' => 'db-chat-model',
        ])->assertSuccessful()->assertJsonPath('data.default_chat_model', 'db-chat-model');

        $this->assertSame('db-chat-model', AiPlatformConfigService::resolveTextDefault('chat', 'ai.text.default_chat_model', 'x'));

        // 空值清除覆盖 → 回退 env/config
        $this->auth()->putJson('/api/v1/admin/ai/defaults', [
            'default_chat_model' => '',
        ])->assertSuccessful()->assertJsonPath('data.default_chat_model', 'env-chat-model');

        $this->assertNull(SystemSetting::get(AiPlatformConfigService::GROUP_DEFAULTS, 'default_chat_model'));
    }

    public function test_defaults_update_rejects_unknown_keys(): void
    {
        $this->auth()->putJson('/api/v1/admin/ai/defaults', [
            'hacker_key' => 'evil',
            'default_chat_model' => 'ok-model',
        ])->assertStatus(422);
    }

    // ==================================================================
    // 租户 AI 配置
    // ==================================================================

    public function test_tenant_config_upsert_across_tenants(): void
    {
        // 初始未配置
        $this->auth()->getJson("/api/v1/admin/ai/tenants/{$this->tenantId}/config")
            ->assertSuccessful()
            ->assertJsonPath('data.configured', false);

        // upsert 创建
        $this->auth()->putJson("/api/v1/admin/ai/tenants/{$this->tenantId}/config", [
            'text_enabled' => true,
            'image_enabled' => false,
            'monthly_budget_limit' => 500,
            'overage_action' => 'warn',
        ])->assertSuccessful()->assertJsonPath('data.image_enabled', false);

        $config = AiTenantConfig::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->tenantId)
            ->first();
        $this->assertNotNull($config);
        $this->assertFalse((bool) $config->image_enabled);
        $this->assertSame('warn', $config->overage_action);

        // 非法超额策略拒绝
        $this->auth()->putJson("/api/v1/admin/ai/tenants/{$this->tenantId}/config", [
            'overage_action' => 'explode',
        ])->assertStatus(422);

        // 租户列表包含该租户
        $this->auth()->getJson('/api/v1/admin/ai/tenants')
            ->assertSuccessful()
            ->assertJsonPath('data.0.tenant_id', $this->tenantId);
    }

    public function test_tenant_config_update_returns_404_for_unknown_tenant(): void
    {
        $this->auth()->putJson('/api/v1/admin/ai/tenants/999999/config', [
            'text_enabled' => true,
        ])->assertNotFound();
    }

    // ==================================================================
    // 模型目录
    // ==================================================================

    public function test_catalog_sync_fetches_models_from_provider_endpoint(): void
    {
        config(['ai.providers.testprov' => [
            'url' => 'https://llm.test.local/v1',
            'api_key' => 'sk-test',
        ]]);

        Http::fake([
            'llm.test.local/*' => Http::response(['data' => [
                ['id' => 'model-a'],
                ['id' => 'model-b'],
            ]]),
        ]);

        $this->auth()->postJson('/api/v1/admin/ai/catalog/sync', ['provider' => 'testprov'])
            ->assertSuccessful()
            ->assertJsonPath('success', true);

        $this->auth()->getJson('/api/v1/admin/ai/catalog')
            ->assertSuccessful()
            ->assertJsonPath('data.testprov.cached', true)
            ->assertJsonPath('data.testprov.count', 2);
    }

    public function test_catalog_sync_rejects_invalid_provider_name(): void
    {
        $this->auth()->postJson('/api/v1/admin/ai/catalog/sync', ['provider' => 'bad-name!'])
            ->assertStatus(422);
    }

    // ==================================================================
    // Provider 连接测试
    // ==================================================================

    public function test_provider_test_uses_db_override_and_reports_model_count(): void
    {
        // env/config 未配置该 provider，DB 补录 url/key
        SystemSetting::set('ai_provider_dbprov', 'base_url', 'https://llm-db.test.local/v1');
        SystemSetting::set('ai_provider_dbprov', 'api_key', 'sk-db-key', true);
        AiPlatformConfigService::forgetCached('dbprov');

        Http::fake([
            'llm-db.test.local/*' => Http::response(['data' => [
                ['id' => 'm1'], ['id' => 'm2'], ['id' => 'm3'],
            ]]),
        ]);

        $res = $this->auth()->postJson('/api/v1/admin/ai/providers/dbprov/test')
            ->assertSuccessful()
            ->assertJsonPath('data.model_count', 3)
            ->assertJsonPath('data.source', 'db');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'llm-db.test.local/v1/models')
            && $request->hasHeader('Authorization', 'Bearer sk-db-key'));
    }

    public function test_provider_test_returns_422_when_unconfigured(): void
    {
        $this->auth()->postJson('/api/v1/admin/ai/providers/ghostprov/test')
            ->assertStatus(422);
    }

    // ==================================================================
    // 提供商多源管理（ai_providers）
    // ==================================================================

    public function test_provider_crud_with_api_key_masking(): void
    {
        // 创建（系统级：即使测试上下文带租户，tenant_id 也应为 null）
        $create = $this->auth()->postJson('/api/v1/admin/ai/providers', [
            'code' => 'newprov',
            'name' => 'New Provider',
            'base_url' => 'https://new.test.local/v1',
            'api_key' => 'sk-secret',
            'priority' => 1,
        ]);
        $create->assertCreated()
            ->assertJsonPath('data.code', 'newprov')
            ->assertJsonPath('data.api_key', '********');
        $providerId = $create->json('data.provider_id');

        // 系统级落库：tenant_id 为 null，api_key 加密存储
        $row = DB::table('ai_providers')->where('provider_id', $providerId)->first();
        $this->assertNull($row->tenant_id);
        $this->assertNotSame('sk-secret', $row->api_key);

        // 列表同样掩码
        $this->auth()->getJson('/api/v1/admin/ai/providers')
            ->assertSuccessful()
            ->assertJsonPath('data.0.code', 'newprov')
            ->assertJsonPath('data.0.api_key', '********');

        // 重复 code 拒绝（系统级唯一）
        $this->auth()->postJson('/api/v1/admin/ai/providers', [
            'code' => 'newprov',
            'name' => 'Dup Provider',
        ])->assertStatus(422);

        // 非法 code 拒绝
        $this->auth()->postJson('/api/v1/admin/ai/providers', [
            'code' => 'Bad-Code!',
            'name' => 'Invalid',
        ])->assertStatus(422);

        // 掩码回存不覆盖真实密钥
        $this->auth()->putJson("/api/v1/admin/ai/providers/{$providerId}", [
            'code' => 'newprov',
            'name' => 'Renamed Provider',
            'api_key' => '********',
        ])->assertSuccessful()->assertJsonPath('data.name', 'Renamed Provider');

        AiPlatformConfigService::forgetCached('newprov');
        $config = AiPlatformConfigService::resolveProviderConfig('newprov');
        $this->assertSame('sk-secret', $config['api_key'], '掩码回存不得覆盖真实密钥');

        // 删除
        $this->auth()->deleteJson("/api/v1/admin/ai/providers/{$providerId}")->assertSuccessful();
        $this->assertDatabaseMissing('ai_providers', ['provider_id' => $providerId]);
    }

    public function test_provider_update_returns_404_for_unknown_provider(): void
    {
        $this->auth()->putJson('/api/v1/admin/ai/providers/999999', [
            'code' => 'ghost',
            'name' => 'Ghost',
        ])->assertNotFound();

        $this->auth()->deleteJson('/api/v1/admin/ai/providers/999999')->assertNotFound();
    }

    public function test_provider_record_wins_in_connection_test_source(): void
    {
        // env/config 未配置，仅 ai_providers 记录 → 连接测试应读取它并报 source=db
        AiProvider::create([
            'tenant_id' => null,
            'code' => 'ctprov',
            'name' => 'CT Provider',
            'base_url' => 'https://ct.test.local/v1',
            'api_key' => 'sk-ct-key',
        ]);
        AiPlatformConfigService::forgetCached('ctprov');

        Http::fake([
            'ct.test.local/*' => Http::response(['data' => [
                ['id' => 'm1'], ['id' => 'm2'], ['id' => 'm3'],
            ]]),
        ]);

        $this->auth()->postJson('/api/v1/admin/ai/providers/ctprov/test')
            ->assertSuccessful()
            ->assertJsonPath('data.source', 'db')
            ->assertJsonPath('data.model_count', 3);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'ct.test.local/v1/models')
            && $request->hasHeader('Authorization', 'Bearer sk-ct-key'));
    }
}
