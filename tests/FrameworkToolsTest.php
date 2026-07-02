<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Services\Agent\Tools\SearchTool;
use MultiTenantSaas\Services\Agent\Tools\FileStorageTool;
use MultiTenantSaas\Services\Agent\Tools\EmailTool;
use MultiTenantSaas\Services\Agent\Tools\NotificationTool;
use MultiTenantSaas\Services\Agent\Tools\CacheTool;
use MultiTenantSaas\Services\Agent\Tools\DatabaseQueryTool;
use MultiTenantSaas\Services\Agent\Tools\HttpTool;
use MultiTenantSaas\Services\Agent\Tools\JsonTool;
use MultiTenantSaas\Services\Agent\Tools\DateTimeTool;
use MultiTenantSaas\Services\Agent\Tools\ValidationTool;
use MultiTenantSaas\Services\Agent\Tools\EncryptionTool;
use MultiTenantSaas\Services\Agent\Tools\LoggingTool;
use MultiTenantSaas\Services\Agent\Tools\QueueTool;
use MultiTenantSaas\Tests\Schema\AgentModule;

class FrameworkToolsTest extends TestCase
{
    protected array $uses = [AgentModule::class];

    public function test_search_tool(): void
    {
        $tool = new SearchTool();
        $this->assertSame('search', $tool->name());
        $this->assertSame('core', $tool->category());

        $result = $tool->execute(['query' => 'test', 'limit' => 5]);
        $this->assertSame('test', $result['query']);
        $this->assertSame(5, $result['limit']);
    }

    public function test_file_storage_tool(): void
    {
        $tool = new FileStorageTool();
        $this->assertSame('file_storage', $tool->name());
        $this->assertSame('storage', $tool->category());

        $result = $tool->execute(['action' => 'upload', 'path' => 'test.txt', 'content' => 'Hello']);
        $this->assertTrue($result['success']);
        $this->assertSame('test.txt', $result['path']);
    }

    public function test_file_storage_tool_list(): void
    {
        $tool = new FileStorageTool();
        $result = $tool->execute(['action' => 'list', 'path' => '/']);
        $this->assertArrayHasKey('files', $result);
        $this->assertArrayHasKey('directories', $result);
    }

    public function test_email_tool(): void
    {
        $tool = new EmailTool();
        $this->assertSame('email', $tool->name());
        $this->assertSame('notification', $tool->category());

        $result = $tool->execute(['to' => 'test@example.com', 'subject' => 'Test', 'body' => 'Hello']);
        $this->assertTrue($result['success']);
        $this->assertSame('test@example.com', $result['to']);
    }

    public function test_notification_tool(): void
    {
        $tool = new NotificationTool();
        $this->assertSame('notification', $tool->name());

        $result = $tool->execute(['user_id' => 100, 'message' => 'Hello', 'channel' => 'email']);
        $this->assertIsArray($result);
        $this->assertTrue(isset($result['success']) || isset($result['error']));
    }

    public function test_notification_tool_empty_message(): void
    {
        $tool = new NotificationTool();
        $result = $tool->execute(['user_id' => 100, 'message' => '']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_cache_tool(): void
    {
        $tool = new CacheTool();
        $this->assertSame('cache', $tool->name());

        $setResult = $tool->execute(['action' => 'set', 'key' => 'test_key', 'value' => 'test_value']);
        $this->assertTrue($setResult['success']);

        $getResult = $tool->execute(['action' => 'get', 'key' => 'test_key']);
        $this->assertSame('test_value', $getResult['value']);

        $hasResult = $tool->execute(['action' => 'has', 'key' => 'test_key']);
        $this->assertTrue($hasResult['exists']);

        $delResult = $tool->execute(['action' => 'delete', 'key' => 'test_key']);
        $this->assertTrue($delResult['success']);
    }

    public function test_database_query_tool(): void
    {
        $tool = new DatabaseQueryTool();
        $this->assertSame('database_query', $tool->name());

        $result = $tool->execute(['table' => '', 'columns' => ['*']]);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_database_query_tool_blocked_table(): void
    {
        $tool = new DatabaseQueryTool();
        $result = $tool->execute(['table' => 'secret_table']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Table not allowed', $result['error']);
    }

    public function test_http_tool(): void
    {
        $tool = new HttpTool();
        $this->assertSame('http', $tool->name());

        $result = $tool->execute(['url' => '']);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_http_tool_blocks_localhost(): void
    {
        $tool = new HttpTool();
        $result = $tool->execute(['url' => 'http://localhost/test']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Blocked host', $result['error']);
    }

    public function test_http_tool_blocks_private_ip(): void
    {
        $tool = new HttpTool();
        $result = $tool->execute(['url' => 'http://192.168.1.1/test']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Blocked host', $result['error']);
    }

    public function test_http_tool_blocks_invalid_method(): void
    {
        $tool = new HttpTool();
        $result = $tool->execute(['url' => 'https://example.com', 'method' => 'OPTIONS']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Method not allowed', $result['error']);
    }

    public function test_json_tool(): void
    {
        $tool = new JsonTool();
        $this->assertSame('json', $tool->name());

        $encodeResult = $tool->execute(['action' => 'encode', 'data' => ['key' => 'value']]);
        $this->assertSame('{"key":"value"}', $encodeResult['result']);

        $decodeResult = $tool->execute(['action' => 'decode', 'data' => '{"key":"value"}']);
        $this->assertSame(['key' => 'value'], $decodeResult['result']);

        $validateResult = $tool->execute(['action' => 'validate', 'data' => '{"valid":true}']);
        $this->assertTrue($validateResult['valid']);
    }

    public function test_datetime_tool(): void
    {
        $tool = new DateTimeTool();
        $this->assertSame('datetime', $tool->name());

        $nowResult = $tool->execute(['action' => 'now']);
        $this->assertNotEmpty($nowResult['result']);

        $formatResult = $tool->execute(['action' => 'format', 'date' => '2026-07-02', 'format' => 'Y/m/d']);
        $this->assertSame('2026/07/02', $formatResult['result']);
    }

    public function test_validation_tool(): void
    {
        $tool = new ValidationTool();
        $this->assertSame('validation', $tool->name());

        $result = $tool->execute([
            'data' => ['email' => 'test@example.com'],
            'rules' => ['email' => 'required|email'],
        ]);
        $this->assertTrue($result['valid']);

        $invalidResult = $tool->execute([
            'data' => ['email' => 'invalid'],
            'rules' => ['email' => 'required|email'],
        ]);
        $this->assertFalse($invalidResult['valid']);
    }

    public function test_encryption_tool(): void
    {
        $tool = new EncryptionTool();
        $this->assertSame('encryption', $tool->name());

        $encResult = $tool->execute(['action' => 'encrypt', 'data' => 'secret']);
        $this->assertNotEmpty($encResult['result']);

        $decResult = $tool->execute(['action' => 'decrypt', 'data' => $encResult['result']]);
        $this->assertSame('secret', $decResult['result']);

        $hashResult = $tool->execute(['action' => 'hash', 'data' => 'test']);
        $this->assertNotEmpty($hashResult['result']);
    }

    public function test_logging_tool(): void
    {
        $tool = new LoggingTool();
        $this->assertSame('logging', $tool->name());

        $result = $tool->execute(['level' => 'info', 'message' => 'Test log']);
        $this->assertTrue($result['success']);
        $this->assertSame('info', $result['level']);
    }

    public function test_queue_tool(): void
    {
        $tool = new QueueTool();
        $this->assertSame('queue', $tool->name());

        $result = $tool->execute(['action' => 'size', 'queue' => 'default']);
        $this->assertArrayHasKey('size', $result);
    }

    public function test_all_tools_implement_contract(): void
    {
        $tools = [
            new SearchTool(),
            new FileStorageTool(),
            new EmailTool(),
            new NotificationTool(),
            new CacheTool(),
            new DatabaseQueryTool(),
            new HttpTool(),
            new JsonTool(),
            new DateTimeTool(),
            new ValidationTool(),
            new EncryptionTool(),
            new LoggingTool(),
            new QueueTool(),
        ];

        $this->assertCount(13, $tools);

        foreach ($tools as $tool) {
            $this->assertInstanceOf(\MultiTenantSaas\Contracts\ToolContract::class, $tool);
            $this->assertNotEmpty($tool->name());
            $this->assertNotEmpty($tool->description());
            $this->assertNotEmpty($tool->category());
        }
    }
}
