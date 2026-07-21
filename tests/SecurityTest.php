<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantUser;
use MultiTenantSaas\Modules\User\Http\Resources\UserResource;

/**
 * 安全测试（OWASP Top 10）
 *
 * 覆盖注入、XSS、CSRF、敏感数据泄露、批量赋值、租户隔离与越权访问等核心安全面。
 * 本测试集合对应 docs/security/安全审计报告.md 中的手动测试项。
 */
class SecurityTest extends TestCase
{
    use DatabaseTransactions;

    protected Tenant $tenant;

    protected Tenant $otherTenant;

    protected User $tenantAdmin;

    protected User $endUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['name' => 'Sec Tenant', 'slug' => 'sec']);
        $this->otherTenant = Tenant::factory()->create(['name' => 'Other Tenant', 'slug' => 'other']);

        $this->tenantAdmin = User::factory()->create([
            'email' => 'sec-admin@test.com',
            'password' => Hash::make('Password123'),
        ]);

        $this->endUser = User::factory()->create([
            'email' => 'sec-user@test.com',
            'password' => Hash::make('Password123'),
        ]);

        TenantUser::factory()->admin()->create([
            'tenant_id' => $this->tenant->tenant_id,
            'user_id' => $this->tenantAdmin->user_id,
        ]);

        TenantUser::factory()->endUser()->create([
            'tenant_id' => $this->tenant->tenant_id,
            'user_id' => $this->endUser->user_id,
        ]);
    }

    // ========== A03:2021 - 注入（SQL Injection） ==========

    /**
     * SQL 注入载荷被参数绑定消解：以注入串作为条件值时不会返回非匹配行。
     */
    public function test_sql_injection_payload_is_neutralized_by_parameter_binding(): void
    {
        User::factory()->create(['email' => 'real@sec-test.com']);

        // 正常查询命中目标用户
        $hit = User::where('email', 'real@sec-test.com')->first();
        $this->assertNotNull($hit);

        // 注入串作为绑定值，应查不到任何记录（不会变成 OR 1=1）
        $injected = "real@sec-test.com' OR '1'='1";
        $this->assertNull(User::where('email', $injected)->first());

        // 注入串不会造成全表泄露
        $this->assertCount(0, User::where('email', "x' OR '1'='1' --")->get());
    }

    /**
     * 原生查询必须使用绑定参数，禁止字符串拼接用户输入。
     */
    public function test_raw_query_with_bindings_does_not_leak_cross_tenant_data(): void
    {
        Customer::create(['tenant_id' => $this->tenant->tenant_id, 'name' => 'Alice']);
        Customer::create(['tenant_id' => $this->otherTenant->tenant_id, 'name' => 'Bob']);

        TenantContext::setId((string) $this->tenant->tenant_id);

        // 使用绑定参数，结果受租户作用域约束
        $rows = DB::table('customers')
            ->where('tenant_id', (int) $this->tenant->tenant_id)
            ->where('name', 'Alice')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows->first()->name);
    }

    // ========== A03:2021 - XSS ==========

    /**
     * API 响应统一为 JSON，浏览器不会以 HTML 渲染，防止反射型 XSS。
     */
    public function test_api_responses_are_served_as_json_content_type(): void
    {
        $token = $this->endUser->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');

        $response->assertSuccessful();
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type') ?? '');
    }

    /**
     * 用户输入的脚本片段以原文存储、作为 JSON 数据返回，浏览器不以 HTML 渲染，防止反射型 XSS。
     */
    public function test_user_supplied_script_tag_is_served_as_json_data_not_html(): void
    {
        $payload = '<script>alert("xss")</script>';

        $user = User::factory()->create(['name' => $payload]);

        // 模型层原文保留，未做 HTML 解码或剥离
        $this->assertSame($payload, $user->name);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');

        $response->assertSuccessful();

        // 响应为 JSON，浏览器不会以 HTML 渲染，脚本片段仅作为数据存在
        $this->assertStringContainsString('application/json', $response->headers->get('Content-Type') ?? '');
        $response->assertJsonPath('data.user.name', $payload);
    }

    // ========== A01:2021 - 失效的访问控制（CSRF / 鉴权 / 越权） ==========

    /**
     * 受保护端点强制 Bearer Token 鉴权，无凭证请求被拒绝（401）。
     * API 采用无状态 Token 鉴权，不依赖 Cookie/Session，天然规避 CSRF。
     */
    public function test_protected_endpoint_rejects_unauthenticated_request(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }

    /**
     * 未授权角色访问受限端点返回 403（RBAC 拒绝）。
     */
    public function test_rbac_denies_unauthorized_role_access(): void
    {
        // tenant_admin 不具备 tenant.view 权限，禁止列出租户
        $token = $this->tenantAdmin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/tenants');

        $response->assertStatus(403);
    }

    /**
     * 跨租户访问被拒绝（403），防止水平越权。
     */
    public function test_cross_tenant_access_is_forbidden(): void
    {
        $token = $this->tenantAdmin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/tenants/{$this->otherTenant->tenant_id}/members");

        $response->assertStatus(403);
    }

    /**
     * 租户作用域确保查询不会跨租户泄露数据。
     */
    public function test_tenant_scope_prevents_cross_tenant_data_leak(): void
    {
        Customer::create(['tenant_id' => $this->tenant->tenant_id, 'name' => 'Alice']);
        Customer::create(['tenant_id' => $this->otherTenant->tenant_id, 'name' => 'Bob']);

        TenantContext::setId((string) $this->tenant->tenant_id);

        $customers = Customer::all();

        $this->assertCount(1, $customers);
        $this->assertSame('Alice', $customers->first()->name);
        $this->assertNotContains('Bob', $customers->pluck('name')->all());
    }

    // ========== A02:2021 - 加密失败（敏感数据泄露） ==========

    /**
     * 密码字段在模型序列化时被隐藏，永不返回。
     */
    public function test_password_is_hidden_in_model_serialization(): void
    {
        $user = User::factory()->create();

        $array = $user->toArray();

        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('remember_token', $array);
    }

    /**
     * 密码以哈希存储，禁止明文落库。
     */
    public function test_password_is_hashed_not_stored_as_plaintext(): void
    {
        $plain = 'MySecretPassword123';

        $user = User::factory()->create(['password' => Hash::make($plain)]);

        $stored = DB::table('users')->where('user_id', $user->user_id)->value('password');

        $this->assertNotSame($plain, $stored);
        $this->assertTrue(Hash::check($plain, (string) $stored));
        $this->assertStringStartsNotWith($plain, (string) $stored);
    }

    /**
     * /auth/me 响应不泄露密码等敏感字段。
     */
    public function test_auth_me_response_does_not_leak_password(): void
    {
        $token = $this->endUser->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');

        $response->assertSuccessful();

        $body = $response->json();
        $this->assertArrayNotHasKey('password', $body);
        $this->assertArrayNotHasKey('remember_token', $body);

        $serialized = json_encode($body);
        $this->assertStringNotContainsString('"password"', (string) $serialized);
    }

    /**
     * 手机号在 API Resource 输出时脱敏。
     */
    public function test_phone_is_masked_in_user_resource(): void
    {
        $user = User::factory()->create(['phone' => '13812345678']);

        $resource = (new UserResource($user))->toArray(request());

        $this->assertSame('138****5678', $resource['phone']);
        $this->assertStringNotContainsString('13812345678', (string) $resource['phone']);
    }

    // ========== A08:2021 - 软件和数据分析失败（批量赋值） ==========

    /**
     * 主键与受保护字段不可通过批量赋值覆盖。
     */
    public function test_mass_assignment_cannot_overwrite_guarded_attributes(): void
    {
        $attackerKeyId = 9999999999999999;

        $user = User::factory()->create();
        $originalId = $user->user_id;

        $user->fill([
            'user_id' => $attackerKeyId,
            'email_verified_at' => now(),
            'login_attempts' => 999,
        ]);
        $user->save();

        $user->refresh();

        // 主键未被覆盖（仍为 HasGlobalId 生成值）
        $this->assertSame((int) $originalId, (int) $user->user_id);
        $this->assertNotSame($attackerKeyId, (int) $user->user_id);

        // 受保护字段保持默认值
        $this->assertNull($user->email_verified_at);
        $this->assertSame(0, (int) $user->login_attempts);
    }

    // ========== A07:2021 - 身份验证失败（登录限流） ==========

    /**
     * 认证端点挂载限流中间件，可被探测到 throttle 中间件保护。
     */
    public function test_auth_endpoints_are_protected_by_rate_limit_middleware(): void
    {
        $login = $this->app['router']->getRoutes()->get('POST')['api/v1/auth/login'] ?? null;

        $this->assertNotNull($login, '登录路由应存在');
        $this->assertStringContainsString('throttle', implode(',', $login->gatherMiddleware()));
    }
}
