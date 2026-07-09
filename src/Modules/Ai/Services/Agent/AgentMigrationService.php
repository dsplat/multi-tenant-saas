<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Agent;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Concerns\EnsuresTenantContext;
use MultiTenantSaas\Modules\Ai\Models\Agent;

class AgentMigrationService
{
    use EnsuresTenantContext;

    protected int $currentVersion = 2;

    public function __construct() {}

    public function migrateAgent(int $tenantId, int $agentId): bool
    {
        $this->ensureTenantContext($tenantId);

        $agent = Agent::where('agent_id', $agentId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$agent) {
            return false;
        }

        if (!$this->needsMigration($tenantId, $agentId)) {
            return true;
        }

        return $this->performMigration($agent);
    }

    public function migrateAll(int $tenantId): array
    {
        $this->ensureTenantContext($tenantId);

        $agents = Agent::where('tenant_id', $tenantId)
            ->where('version', '<', $this->currentVersion)
            ->get();

        $results = [
            'total' => $agents->count(),
            'migrated' => 0,
            'failed' => 0,
        ];

        foreach ($agents as $agent) {
            if ($this->performMigration($agent)) {
                $results['migrated']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    public function needsMigration(int $tenantId, int $agentId): bool
    {
        $this->ensureTenantContext($tenantId);

        $agent = Agent::where('agent_id', $agentId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$agent) {
            return false;
        }

        return $agent->version < $this->currentVersion;
    }

    public function rollback(int $tenantId, int $agentId): bool
    {
        $this->ensureTenantContext($tenantId);

        $agent = Agent::where('agent_id', $agentId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$agent) {
            return false;
        }

        if ($agent->version <= 1) {
            return false;
        }

        return $this->performRollback($agent);
    }

    protected function performMigration(Agent $agent): bool
    {
        $fromVersion = $agent->version;

        if ($fromVersion >= $this->currentVersion) {
            return true;
        }

        try {
            DB::transaction(function () use ($agent, $fromVersion) {
                for ($v = $fromVersion; $v < $this->currentVersion; $v++) {
                    $this->applyMigrationStep($agent, $v);
                }
                $agent->version = $this->currentVersion;
                $agent->save();
            });

            return true;
        } catch (\Throwable $e) {
            Log::error('Agent migration failed', [
                'agent_id' => $agent->agent_id,
                'tenant_id' => $agent->tenant_id,
                'from_version' => $fromVersion,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function performRollback(Agent $agent): bool
    {
        $fromVersion = $agent->version;

        if ($fromVersion <= 1) {
            return false;
        }

        try {
            DB::transaction(function () use ($agent, $fromVersion) {
                $targetVersion = $fromVersion - 1;
                $this->applyRollbackStep($agent, $fromVersion);
                $agent->version = $targetVersion;
                $agent->save();
            });

            return true;
        } catch (\Throwable $e) {
            Log::error('Agent rollback failed', [
                'agent_id' => $agent->agent_id,
                'tenant_id' => $agent->tenant_id,
                'from_version' => $fromVersion,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function applyMigrationStep(Agent $agent, int $fromVersion): void
    {
        switch ($fromVersion) {
            case 1:
                $this->migrateFromV1ToV2($agent);
                break;
        }
    }

    protected function applyRollbackStep(Agent $agent, int $fromVersion): void
    {
        switch ($fromVersion) {
            case 2:
                $this->rollbackFromV2ToV1($agent);
                break;
        }
    }

    protected function migrateFromV1ToV2(Agent $agent): void
    {
        $modelConfig = $agent->model_config ?? [];

        $defaults = [
            'provider' => 'openai',
            'model' => 'gpt-4',
            'temperature' => 0.7,
            'max_tokens' => 4096,
        ];

        $agent->model_config = array_merge($defaults, $modelConfig);

        if (!is_array($agent->tools)) {
            $agent->tools = [];
        }
    }

    protected function rollbackFromV2ToV1(Agent $agent): void
    {
        $modelConfig = $agent->model_config ?? [];
        $v2Defaults = ['provider', 'model', 'temperature', 'max_tokens'];

        foreach ($v2Defaults as $key) {
            unset($modelConfig[$key]);
        }

        $agent->model_config = $modelConfig;
    }
}
