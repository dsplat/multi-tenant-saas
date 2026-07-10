<?php

namespace MultiTenantSaas\Modules\Ai\Services\Tool;

use MultiTenantSaas\Contracts\ToolContract;

class ToolRegistry
{
    protected array $tools = [];

    protected array $categories = ['Core', 'AI', 'Storage', 'Knowledge', 'Channel', 'Workflow'];

    public function register(ToolContract $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function get(string $name): ?ToolContract
    {
        return $this->tools[$name] ?? null;
    }

    public function getByCategory(string $category): array
    {
        return array_filter($this->tools, fn ($t) => $t->category() === $category);
    }

    public function all(): array
    {
        return $this->tools;
    }

    public function categories(): array
    {
        return $this->categories;
    }
}
