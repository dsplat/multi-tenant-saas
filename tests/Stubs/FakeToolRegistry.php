<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests\Stubs;

use Illuminate\Support\Collection;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Services\Agent\Dto\Tool;

class FakeToolRegistry implements ToolRegistryContract
{
    protected array $tools = [];

    public function register(string $slug, string $handlerClass, array $schema, string $category = 'core'): void
    {
        $this->tools[$slug] = new Tool(
            slug: $slug,
            name: $slug,
            description: "Test tool: {$slug}",
            parametersSchema: $schema,
            handlerClass: $handlerClass,
            category: $category,
        );
    }

    public function all(): Collection
    {
        return collect(array_values($this->tools));
    }

    public function get(string $slug): ?Tool
    {
        return $this->tools[$slug] ?? null;
    }

    public function getToolDefinitions(array $slugs): array
    {
        return [];
    }

    public function execute(string $slug, array $arguments, int $tenantId): mixed
    {
        return ['tool' => $slug, 'arguments' => $arguments, 'result' => 'ok'];
    }

    public function isAvailable(string $slug, int $tenantId): bool
    {
        return isset($this->tools[$slug]);
    }

    public function getByCategory(string $category): Collection
    {
        return collect(array_filter(
            array_values($this->tools),
            fn(Tool $t) => $t->category === $category,
        ));
    }

    public function getCategories(): array
    {
        return [];
    }

    public function getCategoryCounts(): array
    {
        return [];
    }

    public function getFrameworkTools(): Collection
    {
        return collect();
    }

    public function getBusinessTools(): Collection
    {
        return collect();
    }
}
