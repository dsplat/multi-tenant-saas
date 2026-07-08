<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Mcp\McpException;
use MultiTenantSaas\Mcp\McpToolRegistry;

/**
 * 测试用的工具注册表实现
 */
class TestMcpToolRegistry extends McpToolRegistry
{
    public function registerTools(): void
    {
        $this->tool(
            'echo',
            '回显输入的消息',
            [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string', 'description' => '要回显的消息'],
                ],
                'required' => ['message'],
            ],
            fn (array $params) => $params['message']
        );

        $this->tool(
            'add',
            '两数相加',
            [
                'type' => 'object',
                'properties' => [
                    'a' => ['type' => 'number', 'description' => '第一个数'],
                    'b' => ['type' => 'number', 'description' => '第二个数'],
                ],
                'required' => ['a', 'b'],
            ],
            fn (array $params) => $params['a'] + $params['b']
        );

        $this->tool(
            'greet',
            '打招呼',
            [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => '名字'],
                ],
                'required' => ['name'],
            ],
            fn (array $params) => "Hello, {$params['name']}!"
        );
    }
}

class McpToolRegistryTest extends TestCase
{
    protected TestMcpToolRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new TestMcpToolRegistry();
    }

    // ---------- 工具注册 ----------

    public function test_register_tools(): void
    {
        $this->assertEquals(3, $this->registry->toolCount());
    }

    public function test_has_tool(): void
    {
        $this->assertTrue($this->registry->hasTool('echo'));
        $this->assertTrue($this->registry->hasTool('add'));
        $this->assertFalse($this->registry->hasTool('nonexistent'));
    }

    // ---------- 工具列表 ----------

    public function test_list_tools(): void
    {
        $tools = $this->registry->listTools();

        $this->assertCount(3, $tools);
        $this->assertEquals('echo', $tools[0]['name']);
        $this->assertEquals('回显输入的消息', $tools[0]['description']);
        $this->assertArrayHasKey('inputSchema', $tools[0]);
    }

    public function test_list_tools_excludes_handler(): void
    {
        $tools = $this->registry->listTools();

        foreach ($tools as $tool) {
            $this->assertArrayNotHasKey('handler', $tool);
        }
    }

    // ---------- 工具调用 ----------

    public function test_call_echo_tool(): void
    {
        $result = $this->registry->callTool('echo', ['message' => 'Hello MCP']);

        $this->assertEquals('Hello MCP', $result);
    }

    public function test_call_add_tool(): void
    {
        $result = $this->registry->callTool('add', ['a' => 3, 'b' => 5]);

        $this->assertEquals(8, $result);
    }

    public function test_call_greet_tool(): void
    {
        $result = $this->registry->callTool('greet', ['name' => 'World']);

        $this->assertEquals('Hello, World!', $result);
    }

    public function test_call_nonexistent_tool_throws(): void
    {
        try {
            $this->registry->callTool('nonexistent', []);
            $this->fail('Expected McpException was not thrown');
        } catch (McpException $e) {
            $this->assertEquals('Tool [nonexistent] not found.', $e->getMessage());
            $this->assertEquals(McpException::CODE_METHOD_NOT_FOUND, $e->getErrorCode());
        }
    }

    // ---------- 异常测试 ----------

    public function test_duplicate_tool_throws(): void
    {
        $registry = new class () extends McpToolRegistry {
            public function registerTools(): void
            {
                $this->tool('test', '测试', [], fn () => 'ok');
                $this->tool('test', '重复', [], fn () => 'dup');
            }
        };

        try {
            $registry->listTools();
            $this->fail('Expected McpException was not thrown');
        } catch (McpException $e) {
            $this->assertEquals('Tool [test] is already registered.', $e->getMessage());
            $this->assertEquals(McpException::CODE_INVALID_REQUEST, $e->getErrorCode());
        }
    }

    // ---------- JSON-RPC 错误码 ----------

    public function test_exception_error_codes(): void
    {
        $this->assertEquals(-32700, McpException::CODE_PARSE_ERROR);
        $this->assertEquals(-32600, McpException::CODE_INVALID_REQUEST);
        $this->assertEquals(-32601, McpException::CODE_METHOD_NOT_FOUND);
        $this->assertEquals(-32602, McpException::CODE_INVALID_PARAMS);
        $this->assertEquals(-32603, McpException::CODE_INTERNAL_ERROR);
    }

    public function test_exception_factory_methods(): void
    {
        $e = McpException::parseError('test');
        $this->assertEquals(-32700, $e->getErrorCode());

        $e = McpException::invalidRequest('test');
        $this->assertEquals(-32600, $e->getErrorCode());

        $e = McpException::methodNotFound('test');
        $this->assertEquals(-32601, $e->getErrorCode());

        $e = McpException::invalidParams('test');
        $this->assertEquals(-32602, $e->getErrorCode());

        $e = McpException::internalError('test');
        $this->assertEquals(-32603, $e->getErrorCode());
    }

    public function test_exception_to_json_rpc_error(): void
    {
        // 直接构造带 errorData 的异常
        $e = new McpException('Invalid JSON', McpException::CODE_INVALID_REQUEST, null, ['line' => 1]);

        $error = $e->toJsonRpcError();

        $this->assertEquals(-32600, $error['code']);
        $this->assertEquals('Invalid JSON', $error['message']);
        $this->assertEquals(['line' => 1], $error['data']);
    }

    public function test_exception_without_data(): void
    {
        $e = McpException::internalError('Server error');

        $error = $e->toJsonRpcError();

        $this->assertArrayNotHasKey('data', $error);
    }
}
