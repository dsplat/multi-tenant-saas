<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Contracts\Http\Kernel;
use MultiTenantSaas\Middleware\IdentifyTenant;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Modules\Ai\Models\AgentTool;
use MultiTenantSaas\Tests\Schema\AgentModule;

class ToolControllerTest extends TestCase
{
    protected array $uses = [AgentModule::class];

    protected Tenant $tenant;

    protected Tenant $otherTenant;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)
            ->prependMiddleware(IdentifyTenant::class);

        $this->tenant = Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        $this->otherTenant = Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);
        $this->user = User::create(['name' => 'Test', 'email' => 'test@test.com', 'password' => bcrypt('password')]);
    }

    protected function authHeaders(int $tenantId = 1001): array
    {
        $token = $this->user->createToken('test-' . uniqid())->plainTextToken;

        return [
            'Authorization' => "Bearer {$token}",
            'X-Tenant-ID' => (string) $tenantId,
            'Accept' => 'application/json',
        ];
    }

    protected function createTool(int $tenantId = 1001, array $overrides = []): AgentTool
    {
        return AgentTool::forceCreate(array_merge([
            'tool_id' => random_int(1000000000000000, 9007199254740991),
            'tenant_id' => $tenantId,
            'name' => 'Test Tool',
            'slug' => 'test-tool-' . uniqid(),
            'description' => 'A test tool',
            'parameters_schema' => ['type' => 'object'],
            'handler_class' => 'App\\Handlers\\TestHandler',
            'enabled' => true,
        ], $overrides));
    }

    // ========== index ==========

    public function test_index_returns_tools(): void
    {
        $this->createTool();

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/tools')
            ->assertSuccessful()
            ->assertJson(['success' => true])
            ->assertJsonCount(1, 'data');
    }

    public function test_index_includes_global_tools(): void
    {
        $this->createTool(0);
        $this->createTool(1001);

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/tools')
            ->assertSuccessful()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_excludes_other_tenant_tools(): void
    {
        $this->createTool(1002);
        $this->createTool(1001);

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/tools')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data');
    }

    public function test_index_excludes_disabled_tools(): void
    {
        $this->createTool(1001, ['enabled' => false]);

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/tools')
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    }

    // ========== show ==========

    public function test_show_returns_tool_detail(): void
    {
        $tool = $this->createTool();

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/tools/{$tool->slug}")
            ->assertSuccessful()
            ->assertJson(['success' => true])
            ->assertJsonPath('data.slug', $tool->slug);
    }

    public function test_show_returns_global_tool(): void
    {
        $tool = $this->createTool(0);

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/tools/{$tool->slug}")
            ->assertSuccessful();
    }

    public function test_show_returns_404_for_other_tenant_tool(): void
    {
        $tool = $this->createTool(1002);

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/tools/{$tool->slug}")
            ->assertStatus(404);
    }

    public function test_show_returns_404_for_nonexistent_tool(): void
    {
        $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/tools/nonexistent-slug')
            ->assertStatus(404);
    }

    // ========== store ==========

    public function test_store_creates_tool(): void
    {
        $slug = 'new-tool-' . uniqid();

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/tools', [
                'name' => 'New Tool',
                'slug' => $slug,
                'description' => 'A new tool',
                'parameters_schema' => ['type' => 'object'],
                'handler_class' => 'App\\Handlers\\NewHandler',
            ])
            ->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.slug', $slug);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/tools', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'slug', 'description', 'parameters_schema', 'handler_class']);
    }

    public function test_store_validates_slug_max_length(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/tools', [
                'name' => 'Tool',
                'slug' => str_repeat('a', 101),
                'description' => 'desc',
                'parameters_schema' => ['type' => 'object'],
                'handler_class' => 'App\\Handlers\\Test',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    // ========== update ==========

    public function test_update_updates_tool(): void
    {
        $tool = $this->createTool();

        $this->withHeaders($this->authHeaders())
            ->putJson("/api/v1/tools/{$tool->slug}", ['name' => 'Updated Tool'])
            ->assertSuccessful()
            ->assertJsonPath('data.name', 'Updated Tool');
    }

    public function test_update_returns_404_for_global_tool(): void
    {
        $tool = $this->createTool(0);

        $this->withHeaders($this->authHeaders())
            ->putJson("/api/v1/tools/{$tool->slug}", ['name' => 'Hacked'])
            ->assertStatus(404);
    }

    public function test_update_returns_404_for_other_tenant_tool(): void
    {
        $tool = $this->createTool(1002);

        $this->withHeaders($this->authHeaders())
            ->putJson("/api/v1/tools/{$tool->slug}", ['name' => 'Hacked'])
            ->assertStatus(404);
    }

    // ========== destroy ==========

    public function test_destroy_deletes_tool(): void
    {
        $tool = $this->createTool();

        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/v1/tools/{$tool->slug}")
            ->assertSuccessful()
            ->assertJson(['success' => true]);

        $this->assertNull(AgentTool::withoutGlobalScopes()->where('tool_id', $tool->tool_id)->first());
    }

    public function test_destroy_returns_404_for_global_tool(): void
    {
        $tool = $this->createTool(0);

        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/v1/tools/{$tool->slug}")
            ->assertStatus(404);
    }

    public function test_destroy_returns_404_for_other_tenant_tool(): void
    {
        $tool = $this->createTool(1002);

        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/v1/tools/{$tool->slug}")
            ->assertStatus(404);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->withHeaders(['Accept' => 'application/json'])
            ->getJson('/api/v1/tools')
            ->assertStatus(401);
    }
}
