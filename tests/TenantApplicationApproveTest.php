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
 * 回归点：审批通过创建租户后必须自动安装系统小秘书
 * （否则新租户 console 小助手报「AI 小助手尚未初始化」404）。
 *
 * 注意：审批流程在 HTTP 上下文中经 Artisan::call 调 secretary:install，
 * 命令注册不得被 runningInConsole 守卫包住（PHPUnit 本身是 CLI，
 * 无法暴露该回归；生产曾因此报 "The command secretary:install does not exist"）。
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

    public function test_approve_creates_tenant_and_installs_system_secretary(): void
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

        // 关键断言：新租户自动安装了系统小秘书（role=system_secretary）
        $secretary = Agent::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('role', 'system_secretary')
            ->first();
        $this->assertNotNull($secretary, '审批通过后应自动为新租户安装系统小秘书');
        $this->assertTrue((bool) $secretary->enabled);
    }

    public function test_approve_is_idempotent_for_secretary_install(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/admin/applications/{$this->application->application_id}/approve")
            ->assertOk();

        $tenantId = Tenant::where('name', '测试组织')->value('tenant_id');

        // 重复执行 secretary:install 不应产生第二条记录（幂等）
        // 注意：不得传 --silent（命令 signature 无此选项，传了即抛异常）
        \Artisan::call('secretary:install', ['--tenant' => (string) $tenantId]);

        $count = DB::table('agents')
            ->where('tenant_id', $tenantId)
            ->where('role', 'system_secretary')
            ->count();
        $this->assertSame(1, $count);
    }
}
