<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\Workflow\Nodes\ActionNode;
use MultiTenantSaas\Services\Workflow\Nodes\ConditionNode;
use MultiTenantSaas\Tests\Stubs\FakeToolRegistry;
use MultiTenantSaas\Tests\Schema\AgentModule;
use MultiTenantSaas\Tests\Schema\WorkflowModule;

class ActionNodeTest extends TestCase
{
    protected array $uses = [AgentModule::class, WorkflowModule::class];

    private FakeToolRegistry $toolRegistry;
    private ActionNode $actionNode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->toolRegistry = new FakeToolRegistry();
        $this->actionNode = new ActionNode($this->toolRegistry);
    }

    public function test_execute_with_valid_tool(): void
    {
        $this->toolRegistry->register('test_tool', 'Test Tool', 'A test tool', 'TestHandler', []);

        $node = [
            'name' => 'Test Action',
            'type' => 'action',
            'config' => [
                'tool' => 'test_tool',
                'arguments' => ['param' => 'value'],
                'output' => 'action_result',
            ],
        ];

        $result = $this->actionNode->execute($node, [], 1001);

        $this->assertArrayHasKey('action_result', $result);
        $this->assertSame('test_tool', $result['action_result']['tool']);
    }

    public function test_execute_with_empty_tool_returns_context(): void
    {
        $node = [
            'name' => 'Empty Action',
            'type' => 'action',
            'config' => ['tool' => ''],
        ];

        $context = ['existing' => 'data'];
        $result = $this->actionNode->execute($node, $context, 1001);

        $this->assertSame($context, $result);
    }

    public function test_execute_with_no_config_returns_context(): void
    {
        $node = ['name' => 'No Config', 'type' => 'action'];
        $context = ['existing' => 'data'];
        $result = $this->actionNode->execute($node, $context, 1001);

        $this->assertSame($context, $result);
    }

    public function test_execute_with_context_variable_resolution(): void
    {
        $this->toolRegistry->register('echo_tool', 'Echo Tool', 'An echo tool', 'EchoHandler', []);

        $node = [
            'name' => 'Echo Action',
            'type' => 'action',
            'config' => [
                'tool' => 'echo_tool',
                'arguments' => ['input' => '$.my_var'],
                'output' => 'echo_result',
            ],
        ];

        $result = $this->actionNode->execute($node, ['my_var' => 'hello'], 1001);

        $this->assertArrayHasKey('echo_result', $result);
        $this->assertSame('hello', $result['echo_result']['arguments']['input']);
    }

    public function test_execute_with_missing_context_variable(): void
    {
        $this->toolRegistry->register('test_tool', 'Test Tool', 'A test tool', 'TestHandler', []);

        $node = [
            'name' => 'Missing Var',
            'type' => 'action',
            'config' => [
                'tool' => 'test_tool',
                'arguments' => ['input' => '$.nonexistent'],
                'output' => 'result',
            ],
        ];

        $result = $this->actionNode->execute($node, [], 1001);

        $this->assertNull($result['result']['arguments']['input']);
    }

    public function test_execute_with_tool_error_stores_error(): void
    {
        $failingRegistry = new class implements \MultiTenantSaas\Contracts\ToolRegistryContract {
            public function register(string $slug, string $name, string $description, string $handlerClass, array $schema, string $category = 'core'): void {}
            public function all(): \Illuminate\Support\Collection { return collect(); }
            public function get(string $slug): ?\MultiTenantSaas\Services\Agent\Dto\Tool { return null; }
            public function getToolDefinitions(array $slugs): array { return []; }
            public function execute(string $slug, array $arguments, int $tenantId): mixed
            {
                throw new \RuntimeException('Tool execution failed');
            }
            public function isAvailable(string $slug, int $tenantId): bool { return true; }
            public function getByCategory(string $category): \Illuminate\Support\Collection { return collect(); }
            public function getCategories(): array { return []; }
            public function getCategoryCounts(): array { return []; }
            public function getFrameworkTools(): \Illuminate\Support\Collection { return collect(); }
            public function getBusinessTools(): \Illuminate\Support\Collection { return collect(); }
        };

        $actionNode = new ActionNode($failingRegistry);

        $node = [
            'name' => 'Failing Action',
            'type' => 'action',
            'config' => [
                'tool' => 'failing_tool',
                'arguments' => [],
                'error_output' => 'my_error',
            ],
        ];

        $result = $actionNode->execute($node, [], 1001);

        $this->assertArrayHasKey('my_error', $result);
        $this->assertSame('Tool execution failed', $result['my_error']);
    }

    public function test_resolve_arguments(): void
    {
        $argumentDefs = [
            'static' => 'value',
            'dynamic' => '$.context_key',
            'missing' => '$.missing_key',
        ];

        $context = ['context_key' => 'resolved_value'];

        $result = $this->actionNode->resolveArguments($argumentDefs, $context);

        $this->assertSame('value', $result['static']);
        $this->assertSame('resolved_value', $result['dynamic']);
        $this->assertNull($result['missing']);
    }

    public function test_resolve_arguments_with_non_string_values(): void
    {
        $argumentDefs = [
            'number' => 42,
            'array' => [1, 2, 3],
            'null' => null,
        ];

        $result = $this->actionNode->resolveArguments($argumentDefs, []);

        $this->assertSame(42, $result['number']);
        $this->assertSame([1, 2, 3], $result['array']);
        $this->assertNull($result['null']);
    }
}
