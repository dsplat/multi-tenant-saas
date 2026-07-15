<?php

namespace MultiTenantSaas\Tests\Operator;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Operator\Models\Operator;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;
use MultiTenantSaas\Tests\Schema\CoreModule;
use MultiTenantSaas\Tests\Schema\InfrastructureModule;
use MultiTenantSaas\Tests\Schema\RbacModule;
use MultiTenantSaas\Tests\TestCase;

class OperatorControllersTest extends TestCase
{
    protected array $uses = [CoreModule::class, RbacModule::class, InfrastructureModule::class];

    private int $tenantId = 7001;

    private User $admin;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->tenant = Tenant::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Operator Test Tenant',
            'slug' => 'operator-test-tenant',
            'status' => 'active',
        ]);

        $this->admin = User::create([
            'user_id' => 7001,
            'name' => 'Admin',
            'email' => 'operator-admin@test.com',
            'password' => bcrypt('password'),
            'tenant_id' => $this->tenantId,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $operator = new Operator([
            'email' => 'operator-admin@test.com',
            'name' => 'Admin',
            'scope' => 'tenant',
            'is_active' => true,
        ]);
        $operator->operator_id = 70001;
        $operator->save();

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

        TenantContext::setTenantId($this->tenantId);
    }

    // ========== Index ==========

    public function test_index_returns_operators(): void
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->getJson('/api/v1/operators');

        $response->assertSuccessful()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['success', 'data']);
    }

    // ========== Show ==========

    public function test_show_returns_operator(): void
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->getJson('/api/v1/operators/70001');

        $response->assertSuccessful()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['success', 'data']);
    }

    public function test_show_returns_404_for_nonexistent_operator(): void
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->getJson('/api/v1/operators/99999');

        $response->assertStatus(404);
    }

    // ========== Invite ==========

    public function test_invite_creates_operator(): void
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->postJson('/api/v1/operators/invite', [
                'email' => 'new-operator@test.com',
                'role' => 'tenant_admin',
            ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['success', 'data' => ['operator', 'invite_token']]);

        $this->assertDatabaseHas('operators', ['email' => 'new-operator@test.com']);
    }

    public function test_invite_rejects_invalid_email(): void
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->postJson('/api/v1/operators/invite', [
                'email' => 'not-an-email',
                'role' => 'tenant_admin',
            ]);

        $response->assertStatus(422);
    }

    public function test_invite_rejects_invalid_role(): void
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->postJson('/api/v1/operators/invite', [
                'email' => 'valid@test.com',
                'role' => 'nonexistent_role',
            ]);

        $response->assertStatus(422);
    }

    // ========== Update ==========

    public function test_update_operator_profile(): void
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->putJson('/api/v1/operators/70001', [
                'name' => 'Updated Name',
                'phone' => '13800138000',
            ]);

        $response->assertSuccessful()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('operators', [
            'operator_id' => 70001,
            'name' => 'Updated Name',
            'phone' => '13800138000',
        ]);
    }

    public function test_update_rejects_invalid_phone(): void
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->putJson('/api/v1/operators/70001', [
                'phone' => str_repeat('1', 21),
            ]);

        $response->assertStatus(422);
    }

    // ========== Update Role ==========

    public function test_update_role(): void
    {
        // Create another operator to change role
        $op = new Operator([
            'email' => 'role-test@test.com',
            'name' => 'Role Test',
            'scope' => 'tenant',
            'is_active' => true,
        ]);
        $op->operator_id = 70002;
        $op->save();

        $user = User::create([
            'user_id' => 7002,
            'name' => 'Role Test',
            'email' => 'role-test@test.com',
            'password' => bcrypt('password'),
            'tenant_id' => $this->tenantId,
            'is_active' => true,
        ]);

        OperatorTenant::create([
            'operator_id' => 70002,
            'tenant_id' => $this->tenantId,
            'user_id' => 7002,
            'role' => 'end_user',
            'is_active' => true,
            'accepted_at' => now(),
        ]);

        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->putJson('/api/v1/operators/70002/role', [
                'role' => 'tenant_admin',
            ]);

        $response->assertSuccessful()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('operator_tenants', [
            'operator_id' => 70002,
            'tenant_id' => $this->tenantId,
            'role' => 'tenant_admin',
        ]);
    }

    // ========== Toggle Status ==========

    public function test_toggle_status_deactivates_operator(): void
    {
        $op = new Operator([
            'email' => 'toggle-test@test.com',
            'name' => 'Toggle Test',
            'scope' => 'tenant',
            'is_active' => true,
        ]);
        $op->operator_id = 70003;
        $op->save();

        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->postJson('/api/v1/operators/70003/toggle-status');

        $response->assertSuccessful()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('operators', [
            'operator_id' => 70003,
            'is_active' => false,
        ]);
    }

    public function test_toggle_status_activates_operator(): void
    {
        $op = new Operator([
            'email' => 'toggle-test2@test.com',
            'name' => 'Toggle Test 2',
            'scope' => 'tenant',
            'is_active' => false,
        ]);
        $op->operator_id = 70004;
        $op->save();

        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->postJson('/api/v1/operators/70004/toggle-status');

        $response->assertSuccessful()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('operators', [
            'operator_id' => 70004,
            'is_active' => true,
        ]);
    }

    // ========== Resend Invite ==========

    public function test_resend_invite_succeeds(): void
    {
        $op = new Operator([
            'email' => 'resend-test@test.com',
            'name' => 'Resend Test',
            'scope' => 'tenant',
            'is_active' => false,
            'invite_token' => 'old-token',
            'invite_expires_at' => now()->addDays(3),
        ]);
        $op->operator_id = 70005;
        $op->save();

        OperatorTenant::create([
            'operator_id' => 70005,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->admin->user_id,
            'role' => 'end_user',
            'is_active' => false,
            'invited_at' => now(),
        ]);

        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->postJson('/api/v1/operators/70005/resend-invite');

        $response->assertSuccessful()
            ->assertJson(['success' => true]);

        // Token should have been refreshed
        $refreshed = Operator::find(70005);
        $this->assertNotEquals('old-token', $refreshed->invite_token);
    }

    public function test_resend_invite_fails_for_active_operator(): void
    {
        $op = new Operator([
            'email' => 'active-resend@test.com',
            'name' => 'Active Resend',
            'scope' => 'tenant',
            'is_active' => true,
        ]);
        $op->operator_id = 70006;
        $op->save();

        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->postJson('/api/v1/operators/70006/resend-invite');

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    // ========== Remove ==========

    public function test_remove_operator(): void
    {
        $op = new Operator([
            'email' => 'remove-test@test.com',
            'name' => 'Remove Test',
            'scope' => 'tenant',
            'is_active' => true,
        ]);
        $op->operator_id = 70007;
        $op->save();

        $user = User::create([
            'user_id' => 7007,
            'name' => 'Remove Test',
            'email' => 'remove-test@test.com',
            'password' => bcrypt('password'),
            'tenant_id' => $this->tenantId,
            'is_active' => true,
        ]);

        OperatorTenant::create([
            'operator_id' => 70007,
            'tenant_id' => $this->tenantId,
            'user_id' => 7007,
            'role' => 'end_user',
            'is_active' => true,
            'accepted_at' => now(),
        ]);

        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->deleteJson('/api/v1/operators/70007');

        $response->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    // ========== Tenants ==========

    public function test_tenants_returns_list(): void
    {
        $token = $this->admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('X-Tenant-ID', $this->tenantId)
            ->getJson('/api/v1/operators/70001/tenants');

        $response->assertSuccessful()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['success', 'data']);
    }
}
