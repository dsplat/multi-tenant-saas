<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Infrastructure\Models\SystemSetting;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Knowledge\Models\ExternalKbConnection;
use MultiTenantSaas\Modules\Knowledge\Services\ExternalKbService;
use MultiTenantSaas\Tests\Schema\InfrastructureModule;
use MultiTenantSaas\Tests\Schema\KnowledgeModule;

/**
 * ExternalKbService 测试套件
 *
 * 覆盖：连接 CRUD、密钥加密与掩码保留、租户/平台 fallback 链、
 * 平台默认配置完整性校验、租户隔离。
 */
class ExternalKbServiceTest extends TestCase
{
    protected array $uses = [KnowledgeModule::class, InfrastructureModule::class];

    protected ?ExternalKbService $service = null;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['tenant_id' => 2001, 'name' => 'Tenant A', 'slug' => 'kb-tenant-a', 'status' => 'active']);
        Tenant::create(['tenant_id' => 2002, 'name' => 'Tenant B', 'slug' => 'kb-tenant-b', 'status' => 'active']);

        $this->service = $this->app->make(ExternalKbService::class);
    }

    public function test_create_connection_encrypts_api_key(): void
    {
        $connection = $this->service->createConnection(2001, [
            'provider_type' => 'dify',
            'name' => '企业知识库',
            'api_url' => 'https://api.dify.ai',
            'api_key' => 'secret-key-123',
        ]);

        $this->assertNotSame('secret-key-123', $connection->api_key_encrypted);
        $this->assertSame('secret-key-123', $connection->getApiKey());

        $presented = $this->service->presentConnection($connection);
        $this->assertSame('********', $presented['api_key']);
    }

    public function test_update_connection_keeps_api_key_when_masked(): void
    {
        $connection = $this->service->createConnection(2001, [
            'provider_type' => 'dify',
            'name' => '企业知识库',
            'api_url' => 'https://api.dify.ai',
            'api_key' => 'original-key',
        ]);

        $updated = $this->service->updateConnection(2001, $connection->connection_id, [
            'name' => '改名知识库',
            'api_key' => '********',
        ]);

        $this->assertSame('改名知识库', $updated->name);
        $this->assertSame('original-key', $updated->getApiKey());

        // 传新密钥时更新
        $updated = $this->service->updateConnection(2001, $connection->connection_id, [
            'api_key' => 'new-key',
        ]);
        $this->assertSame('new-key', $updated->getApiKey());
    }

    public function test_resolve_prefers_tenant_active_connection(): void
    {
        $this->enablePlatformDefault();

        $this->service->createConnection(2001, [
            'provider_type' => 'ragflow',
            'name' => '租户自有库',
            'api_url' => 'https://ragflow.tenant.com',
            'api_key' => 'tenant-key',
            'config' => ['dataset_id' => 'ds-1'],
        ]);

        $resolved = $this->service->resolveProviderConfig(2001);

        $this->assertSame(ExternalKbService::SOURCE_TENANT, $resolved['source']);
        $this->assertSame('ragflow', $resolved['provider_type']);
        $this->assertSame('https://ragflow.tenant.com', $resolved['config']['api_url']);
        $this->assertSame('tenant-key', $resolved['config']['api_key']);
        $this->assertSame('ds-1', $resolved['config']['dataset_id']);
    }

    public function test_resolve_falls_back_to_platform_default(): void
    {
        $this->enablePlatformDefault();

        $resolved = $this->service->resolveProviderConfig(2001);

        $this->assertSame(ExternalKbService::SOURCE_PLATFORM, $resolved['source']);
        $this->assertSame('dify', $resolved['provider_type']);
        $this->assertSame('https://platform.dify.ai', $resolved['config']['api_url']);
        $this->assertNull($resolved['connection_id']);
    }

    public function test_disabled_tenant_connection_falls_back_to_platform(): void
    {
        $this->enablePlatformDefault();

        $this->service->createConnection(2001, [
            'provider_type' => 'ragflow',
            'name' => '停用连接',
            'api_url' => 'https://ragflow.tenant.com',
            'status' => ExternalKbConnection::STATUS_DISABLED,
        ]);

        $resolved = $this->service->resolveProviderConfig(2001);

        $this->assertSame(ExternalKbService::SOURCE_PLATFORM, $resolved['source']);
    }

    public function test_resolve_returns_null_when_nothing_configured(): void
    {
        $this->assertNull($this->service->resolveProviderConfig(2001));

        $status = $this->service->resolveStatus(2001);
        $this->assertFalse($status['configured']);
        $this->assertNull($status['source']);
    }

    public function test_platform_default_requires_complete_config(): void
    {
        // 启用但缺 api_url
        SystemSetting::set(ExternalKbService::SETTINGS_GROUP, 'enabled', true);
        SystemSetting::set(ExternalKbService::SETTINGS_GROUP, 'provider_type', 'dify');

        $this->assertNull($this->service->getPlatformDefault());

        // 未启用时即使配置完整也不生效
        SystemSetting::set(ExternalKbService::SETTINGS_GROUP, 'api_url', 'https://platform.dify.ai');
        SystemSetting::set(ExternalKbService::SETTINGS_GROUP, 'enabled', false);

        $this->assertNull($this->service->getPlatformDefault());
    }

    public function test_connections_are_tenant_isolated(): void
    {
        $this->service->createConnection(2001, [
            'provider_type' => 'dify',
            'name' => 'A 的连接',
            'api_url' => 'https://api.dify.ai',
        ]);

        $this->assertCount(1, $this->service->listConnections(2001));
        $this->assertCount(0, $this->service->listConnections(2002));
        $this->assertNull($this->service->resolveProviderConfig(2002));
    }

    public function test_delete_connection(): void
    {
        $connection = $this->service->createConnection(2001, [
            'provider_type' => 'dify',
            'name' => '待删除',
            'api_url' => 'https://api.dify.ai',
        ]);

        $this->assertTrue($this->service->deleteConnection(2001, $connection->connection_id));
        $this->assertCount(0, $this->service->listConnections(2001));
    }

    public function test_make_provider_rejects_unknown_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->makeProvider('unknown');
    }

    protected function enablePlatformDefault(): void
    {
        SystemSetting::set(ExternalKbService::SETTINGS_GROUP, 'enabled', true);
        SystemSetting::set(ExternalKbService::SETTINGS_GROUP, 'provider_type', 'dify');
        SystemSetting::set(ExternalKbService::SETTINGS_GROUP, 'api_url', 'https://platform.dify.ai');
        SystemSetting::set(ExternalKbService::SETTINGS_GROUP, 'api_key', 'platform-key', true);
        SystemSetting::set(ExternalKbService::SETTINGS_GROUP, 'dataset_id', 'platform-ds');
    }
}
