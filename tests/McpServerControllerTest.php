<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Http\Controllers\McpServerController;
use MultiTenantSaas\Modules\Ai\Mcp\McpException;

class McpServerControllerTest extends TestCase
{
    public function test_controller_class_exists(): void
    {
        $this->assertTrue(class_exists(McpServerController::class));
    }

    public function test_mcp_exception_codes(): void
    {
        $this->assertEquals(-32700, McpException::CODE_PARSE_ERROR);
        $this->assertEquals(-32600, McpException::CODE_INVALID_REQUEST);
        $this->assertEquals(-32601, McpException::CODE_METHOD_NOT_FOUND);
        $this->assertEquals(-32602, McpException::CODE_INVALID_PARAMS);
        $this->assertEquals(-32603, McpException::CODE_INTERNAL_ERROR);
    }

    public function test_mcp_exception_factory_methods(): void
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

    public function test_mcp_exception_to_json_rpc_error(): void
    {
        $e = new McpException('Test error', McpException::CODE_INVALID_REQUEST, null, ['key' => 'value']);
        $error = $e->toJsonRpcError();

        $this->assertEquals(-32600, $error['code']);
        $this->assertEquals('Test error', $error['message']);
        $this->assertEquals(['key' => 'value'], $error['data']);
    }
}
