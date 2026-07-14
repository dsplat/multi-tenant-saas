<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Contracts\Http\Kernel;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Models\AgentConversation;
use MultiTenantSaas\Modules\Ai\Models\AgentToolLog;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\IdentifyTenant;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Tests\Schema\AgentModule;

class AgentStatsControllerTest extends TestCase
{
    protected array $uses = [AgentModule::class];

    protected Tenant $tenant;

    protected Tenant $otherTenant;

    protected User $user;

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)
            ->prependMiddleware(IdentifyTenant::class);

        $this->tenant = Tenant::create(['tenant_id' => 1001, 'name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        $this->otherTenant = Tenant::create(['tenant_id' => 1002, 'name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);
        $this->user = User::create(['name' => 'Test', 'email' => 'test@test.com', 'password' => bcrypt('password')]);
        $this->agent = Agent::forceCreate([
            'agent_id' => 1001,
            'tenant_id' => 1001,
            'name' => 'Stats Agent',
            'role' => 'assistant',
            'system_prompt' => 'prompt',
            'model_config' => ['preferred_model' => 'gpt-4o-mini'],
            'enabled' => true,
        ]);
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

    public function test_stats_returns_metrics(): void
    {
        $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/agents/{$this->agent->agent_id}/stats")
            ->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    public function test_stats_accepts_date_range(): void
    {
        $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/agents/{$this->agent->agent_id}/stats?start_date=2026-01-01&end_date=2026-06-30")
            ->assertSuccessful();
    }

    public function test_stats_returns_404_for_other_tenant_agent(): void
    {
        $otherAgent = Agent::forceCreate([
            'agent_id' => 1002,
            'tenant_id' => 1002,
            'name' => 'Other',
            'role' => 'assistant',
            'system_prompt' => 'p',
            'model_config' => [],
        ]);

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/agents/{$otherAgent->agent_id}/stats")
            ->assertStatus(404);
    }

    public function test_token_usage_returns_usage(): void
    {
        $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/agents/{$this->agent->agent_id}/token-usage")
            ->assertSuccessful()
            ->assertJson(['success' => true]);
    }

    public function test_token_usage_returns_404_for_other_tenant(): void
    {
        $otherAgent = Agent::forceCreate([
            'agent_id' => 1002,
            'tenant_id' => 1002,
            'name' => 'Other',
            'role' => 'assistant',
            'system_prompt' => 'p',
            'model_config' => [],
        ]);

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/agents/{$otherAgent->agent_id}/token-usage")
            ->assertStatus(404);
    }

    public function test_cost_returns_estimate(): void
    {
        $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/agents/{$this->agent->agent_id}/cost")
            ->assertSuccessful()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['agent_id', 'start_date', 'end_date', 'estimated_cost']]);
    }

    public function test_cost_returns_404_for_other_tenant(): void
    {
        $otherAgent = Agent::forceCreate([
            'agent_id' => 1002,
            'tenant_id' => 1002,
            'name' => 'Other',
            'role' => 'assistant',
            'system_prompt' => 'p',
            'model_config' => [],
        ]);

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/agents/{$otherAgent->agent_id}/cost")
            ->assertStatus(404);
    }

    public function test_tool_logs_returns_paginated_list(): void
    {
        $conversation = AgentConversation::forceCreate([
            'conversation_id' => random_int(1000000000000000, 9007199254740991),
            'agent_id' => 1001,
            'tenant_id' => 1001,
            'channel' => 'web',
            'status' => 'active',
            'message_count' => 0,
        ]);

        AgentToolLog::forceCreate([
            'log_id' => random_int(1000000000000000, 9007199254740991),
            'conversation_id' => $conversation->conversation_id,
            'agent_id' => 1001,
            'tool_name' => 'search',
            'input' => '{"q":"test"}',
            'output' => '{"result":"ok"}',
            'duration_ms' => 100,
            'status' => 'success',
        ]);

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/agents/{$this->agent->agent_id}/tool-logs")
            ->assertSuccessful()
            ->assertJson(['success' => true])
            ->assertJsonCount(1, 'data');
    }

    public function test_tool_logs_isolates_by_tenant(): void
    {
        $conv1 = AgentConversation::forceCreate([
            'conversation_id' => random_int(1000000000000000, 9007199254740991),
            'agent_id' => 1001,
            'tenant_id' => 1001,
            'channel' => 'web',
            'status' => 'active',
            'message_count' => 0,
        ]);
        $conv2 = AgentConversation::forceCreate([
            'conversation_id' => random_int(1000000000000000, 9007199254740991),
            'agent_id' => 1001,
            'tenant_id' => 1002,
            'channel' => 'web',
            'status' => 'active',
            'message_count' => 0,
        ]);

        AgentToolLog::forceCreate([
            'log_id' => random_int(1000000000000000, 9007199254740991),
            'conversation_id' => $conv1->conversation_id,
            'agent_id' => 1001,
            'tool_name' => 'search',
            'input' => '{}',
            'output' => '{}',
            'duration_ms' => 50,
            'status' => 'success',
        ]);
        AgentToolLog::forceCreate([
            'log_id' => random_int(1000000000000000, 9007199254740991),
            'conversation_id' => $conv2->conversation_id,
            'agent_id' => 1001,
            'tool_name' => 'search',
            'input' => '{}',
            'output' => '{}',
            'duration_ms' => 50,
            'status' => 'success',
        ]);

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/agents/{$this->agent->agent_id}/tool-logs")
            ->assertSuccessful()
            ->assertJsonCount(1, 'data');
    }

    public function test_tool_logs_returns_404_for_other_tenant_agent(): void
    {
        $otherAgent = Agent::forceCreate([
            'agent_id' => 1002,
            'tenant_id' => 1002,
            'name' => 'Other',
            'role' => 'assistant',
            'system_prompt' => 'p',
            'model_config' => [],
        ]);

        $this->withHeaders($this->authHeaders())
            ->getJson("/api/v1/agents/{$otherAgent->agent_id}/tool-logs")
            ->assertStatus(404);
    }
}
