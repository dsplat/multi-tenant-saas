<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Capability;

use MultiTenantSaas\Contracts\CapabilityContract;
use MultiTenantSaas\Models\Capability\CapabilityResult;

class CapabilityRegistry
{
    protected array $capabilities = [];

    public function register(string $name, CapabilityContract $capability): void
    {
        $this->capabilities[$name] = $capability;
    }

    public function get(string $name): ?CapabilityContract
    {
        return $this->capabilities[$name] ?? null;
    }

    public function all(): array
    {
        return $this->capabilities;
    }

    public function has(string $name): bool
    {
        return isset($this->capabilities[$name]);
    }

    /**
     * 获取所有已注册的能力名称
     */
    public function names(): array
    {
        return array_keys($this->capabilities);
    }

    /**
     * 按前缀发现能力
     */
    public function findByPrefix(string $prefix): array
    {
        $result = [];
        foreach ($this->capabilities as $name => $capability) {
            if (str_starts_with($name, $prefix)) {
                $result[$name] = $capability;
            }
        }
        return $result;
    }

    /**
     * 发现所有已注册能力的元数据
     */
    public function discover(): array
    {
        $result = [];
        foreach ($this->capabilities as $name => $capability) {
            $result[] = [
                'name' => $name,
                'class' => get_class($capability),
            ];
        }
        return $result;
    }

    /**
     * 执行指定能力
     */
    public function execute(string $name, array $input): CapabilityResult
    {
        $capability = $this->get($name);

        if (!$capability) {
            return new CapabilityResult(
                capability: $name,
                output: null,
                confidence: 0.0,
                tokenUsage: 0,
                durationMs: 0,
            );
        }

        $start = hrtime(true);
        $result = $capability->execute($input);
        $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);

        return new CapabilityResult(
            capability: $name,
            output: $result->output,
            confidence: $result->confidence,
            tokenUsage: $result->tokenUsage,
            durationMs: $durationMs,
        );
    }
}
