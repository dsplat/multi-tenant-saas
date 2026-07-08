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

    public function test_validation_tool_missing_data(): void
    {
        $tool = new ValidationTool();
        $result = $tool->execute(['data' => [], 'rules' => []]);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Data and rules required', $result['error']);
    }

    public function test_validation_tool_missing_rules(): void
    {
        $tool = new ValidationTool();
        $result = $tool->execute(['data' => ['email' => 'test@example.com']]);
        $this->assertArrayHasKey('error', $result);
    }

    public function test_json_tool_invalid_action(): void
    {
        $tool = new JsonTool();
        $result = $tool->execute(['action' => 'compress', 'data' => '{}']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Unknown action', $result['error']);
    }

    public function test_json_tool_decode_invalid_json(): void
    {
        $tool = new JsonTool();
        $result = $tool->execute(['action' => 'decode', 'data' => '{invalid}']);
        $this->assertNull($result['result']);
    }

    public function test_datetime_tool_invalid_action(): void
    {
        $tool = new DateTimeTool();
        $result = $tool->execute(['action' => 'unknown']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Unknown action', $result['error']);
    }

    public function test_datetime_tool_diff_action(): void
    {
        $tool = new DateTimeTool();
        $result = $tool->execute(['action' => 'diff', 'date' => '2026-07-01']);
        $this->assertNotEmpty($result['result']);
    }

    public function test_datetime_tool_add_action(): void
    {
        $tool = new DateTimeTool();
        $result = $tool->execute(['action' => 'add', 'date' => '2026-07-01', 'unit' => 'days', 'value' => 5]);
        $this->assertNotEmpty($result['result']);
    }

    public function test_encryption_tool_invalid_action(): void
    {
        $tool = new EncryptionTool();
        $result = $tool->execute(['action' => 'sign', 'data' => 'test']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Unknown action', $result['error']);
    }

    public function test_cache_tool_invalid_action(): void
    {
        $tool = new CacheTool();
        $result = $tool->execute(['action' => 'dump', 'key' => 'test']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Unknown action', $result['error']);
    }

    public function test_cache_tool_has_nonexistent_key(): void
    {
        $tool = new CacheTool();
        $result = $tool->execute(['action' => 'has', 'key' => 'nonexistent_key_xyz']);
        $this->assertFalse($result['exists']);
    }

    public function test_search_tool_default_params(): void
    {
        $tool = new SearchTool();
        $result = $tool->execute([]);
        $this->assertSame('', $result['query']);
        $this->assertSame(10, $result['limit']);
        $this->assertSame([], $result['results']);
        $this->assertSame(0, $result['total']);
    }

    public function test_http_tool_blocks_10_network(): void
    {
        $tool = new HttpTool();
        $result = $tool->execute(['url' => 'http://10.0.0.1/test']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Blocked host', $result['error']);
    }

    public function test_http_tool_blocks_172_network(): void
    {
        $tool = new HttpTool();
        $result = $tool->execute(['url' => 'http://172.16.0.1/test']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Blocked host', $result['error']);
    }

    public function test_http_tool_blocks_ftp_scheme(): void
    {
        $tool = new HttpTool();
        $result = $tool->execute(['url' => 'ftp://example.com/test']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Only HTTP/HTTPS allowed', $result['error']);
    }

    public function test_http_tool_invalid_url(): void
    {
        $tool = new HttpTool();
        $result = $tool->execute(['url' => 'not-a-valid-url']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Invalid URL', $result['error']);
    }

    public function test_file_storage_tool_download_nonexistent(): void
    {
        $tool = new FileStorageTool();
        $result = $tool->execute(['action' => 'download', 'path' => 'nonexistent.txt']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('File not found', $result['error']);
    }

    public function test_file_storage_tool_delete_nonexistent(): void
    {
        $tool = new FileStorageTool();
        $result = $tool->execute(['action' => 'delete', 'path' => 'nonexistent.txt']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('File not found', $result['error']);
    }

    public function test_file_storage_tool_exists(): void
    {
        $tool = new FileStorageTool();
        $result = $tool->execute(['action' => 'exists', 'path' => 'test.txt']);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('exists', $result);
        $this->assertArrayHasKey('path', $result);
    }

    public function test_file_storage_tool_size(): void
    {
        $tool = new FileStorageTool();
        $result = $tool->execute(['action' => 'size', 'path' => 'test.txt']);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('size', $result);
        $this->assertArrayHasKey('path', $result);
    }

    public function test_email_tool_empty_params(): void
    {
        $tool = new EmailTool();
        $result = $tool->execute([]);
        $this->assertTrue($result['success']);
        $this->assertSame('', $result['to']);
        $this->assertSame('', $result['subject']);
    }

    public function test_logging_tool_with_context(): void
    {
        $tool = new LoggingTool();
        $result = $tool->execute(['level' => 'warning', 'message' => 'Test warn', 'context' => ['key' => 'val']]);
        $this->assertTrue($result['success']);
        $this->assertSame('warning', $result['level']);
        $this->assertSame('Test warn', $result['message']);
    }

    public function test_queue_tool_purge_action(): void
    {
        $tool = new QueueTool();
        $result = $tool->execute(['action' => 'purge', 'queue' => 'custom']);
        $this->assertTrue($result['success']);
        $this->assertSame('custom', $result['queue']);
    }

    public function test_queue_tool_invalid_action(): void
    {
        $tool = new QueueTool();
        $result = $tool->execute(['action' => 'dispatch']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Unknown action', $result['error']);
    }

    public function test_database_query_tool_allowed_table(): void
    {
        $tool = new DatabaseQueryTool();
        $result = $tool->execute(['table' => 'tenants', 'columns' => ['*'], 'limit' => 5]);
        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('count', $result);
    }

    public function test_database_query_tool_condition_filter(): void
    {
        $tool = new DatabaseQueryTool();
        $result = $tool->execute([
            'table' => 'tenants',
            'columns' => ['*'],
            'conditions' => ['tenant_id' => 1001],
            'limit' => 5,
        ]);
        $this->assertArrayHasKey('results', $result);
    }
}
