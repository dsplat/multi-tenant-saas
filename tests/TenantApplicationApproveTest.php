<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Platform\Models\TenantApplication;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\AiModule;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\InfrastructureModule;
use MultiTenantSaas\Tests\Schema\RbacModule;

/**
 * 租户申请审批测试
 *
 * 开通节奏（懒开通策略）：审批通过只建租户与管理员绑定，
 * 不预装小助手/数字员工；秘书在用户首次打开小助手对话时由
 * AgentProvisioningService::ensureSecretary 自动开通（见 AiStreamingControllerTest），
 * 其余数字员工由秘书按需征得确认后 enable_agent 启用。
 * secretary:install / agents:enable 保留作运维补装工具（幂等回归见下）。
 */
class TenantApplicationApproveTest extends TestCase
{
    protected array $uses = [CoreModule::class, RbacModule::class, InfrastructureModule::class, AiModule::class, AgentModule::class];

    private Operator $reviewer;

    private Operator $applicant;

    private TenantApplication $application;

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

        // 审批人：platform scope Operator（RBAC 直通）
        $this->reviewer = Operator::create([
            'email' => 'reviewer@test.com',
            'name' => 'Reviewer',
            'scope' => 'platform',
            'is_active' => true,
        ]);
        $this->token = $this->reviewer->createToken('test')->plainTextToken;

        // 申请人：无租户 Operator
        $this->applicant = Operator::create([
            'email' => 'applicant@test.com',
            'name' => 'Applicant',
            'scope' => 'tenant',
            'is_active' => true,
        ]);

        $this->application = TenantApplication::create([
            'application_id' => 7001,
            'operator_id' => $this->applicant->operator_id,
            'code' => 'APP-TEST-0001',
            'org_name' => '测试组织',
            'org_industry' => '教育培训',
            'org_size' => '10 人以下',
            'status' => TenantApplication::STATUS_SUBMITTED,
        ]);
    }

    public function test_approve_creates_tenant_without_preinstalling_agents(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/admin/applications/{$this->application->application_id}/approve", [
                'review_notes' => '测试审批',
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        // 租户已创建
        $tenantId = (int) $response->json('data.tenant.tenant_id');
        $this->assertNotNull($tenantId);
        $this->assertDatabaseHas('tenants', ['tenant_id' => $tenantId, 'name' => '测试组织', 'status' => 'active']);

        // 申请人已绑定为租户管理员
        $this->assertDatabaseHas('operator_tenants', [
            'operator_id' => $this->applicant->operator_id,
            'tenant_id' => $tenantId,
            'role' => 'tenant_admin',
        ]);

        // 懒开通策略：审批时不预装任何数字员工（秘书首次对话时自动开通）
        $count = Agent::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->count();
        $this->assertSame(0, $count, '审批通过不应预装数字员工');
    }

    public function test_secretary_install_command_remains_idempotent_backfill(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/admin/applications/{$this->application->application_id}/approve")
            ->assertOk();

        $tenantId = Tenant::where('name', '测试组织')->value('tenant_id');

        // 运维补装命令保持幂等：重复执行不产生第二条记录
        // 注意：不得传 --silent（命令 signature 无此选项，传了即抛异常）
        \Artisan::call('secretary:install', ['--tenant' => (string) $tenantId]);
        \Artisan::call('secretary:install', ['--tenant' => (string) $tenantId]);

        $count = DB::table('agents')
            ->where('tenant_id', $tenantId)
            ->where('role', 'system_secretary')
            ->count();
        $this->assertSame(1, $count);
    }
}
