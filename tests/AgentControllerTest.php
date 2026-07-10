<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Contracts\Http\Kernel;
use MultiTenantSaas\Middleware\IdentifyTenant;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Services\Agent\BuiltinAgentTemplates;
use MultiTenantSaas\Tests\Schema\AgentModule;

class AgentControllerTest extends TestCase
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
        BuiltinAgentTemplates::clearCache();
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

    protected function createAgent(int $tenantId = 1001, array $overrides = []): Agent
    {
        return Agent::forceCreate(array_merge([
            'agent_id' => random_int(1000000000000000, 9007199254740991),
            'tenant_id' => $tenantId,
            'name' => 'Test Agent',
            'role' => 'assistant',
            'system_prompt' => 'You are helpful.',
            'model_config' => ['preferred_model' => 'gpt-4o-mini'],
            'enabled' => true,
        ], $overrides));
    }

    public function test_index_returns_agents_for_tenant(): void
    {
        $this->createAgent();
        $this->createAgent(1002);

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/agents')
            ->assertSuccessful()
            ->assertJson(['success' => true])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Test Agent');
    }

    public function test_index_returns_empty_for_no_agents(): void
    {
        $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/agents')
            ->assertSuccessful()
            ->assertJson(['success' => true, 'data' => []]);
    }

    public function test_show_returns_agent_detail(): void
    {
        $agent = $this->createAgent();

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/agents/{$agent->agent_id}")
            ->assertSuccessful()
            ->assertJsonPath('data.name', 'Test Agent');
    }

    public function test_show_returns_404_for_other_tenant_agent(): void
    {
        $agent = $this->createAgent(1002);

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/agents/{$agent->agent_id}")
            ->assertStatus(404);
    }

    public function test_show_returns_404_for_nonexistent_agent(): void
    {
        $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/agents/9999999999999999')
            ->assertStatus(404);
    }

    public function test_store_creates_agent(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/agents', [
                'name' => 'New Agent',
                'role' => 'sales',
                'system_prompt' => 'You are a sales agent.',
            ])
            ->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.name', 'New Agent');
    }

    public function test_store_validates_required_fields(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/agents', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'role', 'system_prompt']);
    }

    public function test_store_validates_name_max_length(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/agents', [
                'name' => str_repeat('a', 101),
                'role' => 'assistant',
                'system_prompt' => 'prompt',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_update_updates_agent(): void
    {
        $agent = $this->createAgent();

        $this->withHeaders($this->authHeaders())
            ->putJson("/api/v1/agents/{$agent->agent_id}", ['name' => 'Updated'])
            ->assertSuccessful()
            ->assertJsonPath('data.name', 'Updated');
    }

    public function test_update_returns_404_for_other_tenant(): void
    {
        $agent = $this->createAgent(1002);

        $this->withHeaders($this->authHeaders())
            ->putJson("/api/v1/agents/{$agent->agent_id}", ['name' => 'Hacked'])
            ->assertStatus(404);
    }

    public function test_destroy_deletes_agent(): void
    {
        $agent = $this->createAgent();

        $this->withHeaders($this->authHeaders())
            ->deleteJson("/api/v1/agents/{$agent->agent_id}")
            ->assertSuccessful()
            ->assertJson(['success' => true]);

        $this->assertNull(Agent::withoutGlobalScopes()->where('agent_id', $agent->agent_id)->first());
    }

    public function test_enable_enables_agent(): void
    {
        $agent = $this->createAgent(1001, ['enabled' => false]);

        $this->withHeaders($this->authHeaders())
            ->postJson("/api/v1/agents/{$agent->agent_id}/enable")
            ->assertSuccessful();
    }

    public function test_disable_disables_agent(): void
    {
        $agent = $this->createAgent();

        $this->withHeaders($this->authHeaders())
            ->postJson("/api/v1/agents/{$agent->agent_id}/disable")
            ->assertSuccessful();
    }

    public function test_templates_returns_builtin_templates(): void
    {
        $this->withHeaders($this->authHeaders())
            ->getJson('/api/v1/agents/templates')
            ->assertSuccessful()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data']);
    }

    public function test_clone_template_creates_agent(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/v1/agents/templates/1/clone', ['name' => 'Cloned Agent'])
            ->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    public function test_update_model_config(): void
    {
        $agent = $this->createAgent();

        $this->withHeaders($this->authHeaders())
            ->putJson("/api/v1/agents/{$agent->agent_id}/model-config", [
                'model_config' => ['temperature' => 0.5],
            ])
            ->assertSuccessful();
    }

    public function test_update_model_config_validates(): void
    {
        $agent = $this->createAgent();

        $this->withHeaders($this->authHeaders())
            ->putJson("/api/v1/agents/{$agent->agent_id}/model-config", [
                'model_config' => ['temperature' => 3.0],
            ])
            ->assertStatus(422);
    }

    public function test_update_tools(): void
    {
        $agent = $this->createAgent();

        $this->withHeaders($this->authHeaders())
            ->putJson("/api/v1/agents/{$agent->agent_id}/tools", [
                'tool_slugs' => ['search'],
            ])
            ->assertSuccessful();
    }

    public function test_update_knowledge_bases(): void
    {
        $agent = $this->createAgent();

        $this->withHeaders($this->authHeaders())
            ->putJson("/api/v1/agents/{$agent->agent_id}/knowledge-bases", [
                'kb_ids' => [1, 2],
            ])
            ->assertSuccessful();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->withHeaders(['Accept' => 'application/json'])
            ->getJson('/api/v1/agents')
            ->assertStatus(401);
    }
}
