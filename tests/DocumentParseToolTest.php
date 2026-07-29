<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Support\Facades\Storage;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ai\Services\Tool\DocumentParseTool;
use MultiTenantSaas\Modules\Storage\Models\FileUpload;
use MultiTenantSaas\Tests\Schema\PluginModule;
use ZipArchive;

/**
 * document_parse 工具测试
 *
 * 覆盖：参数校验、租户隔离、纯文本/csv 直读、docx 抽取、
 * 长文截断标记、不支持类型的结构化错误。
 */
class DocumentParseToolTest extends TestCase
{
    protected array $uses = [PluginModule::class];

    private DocumentParseTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        TenantContext::setTenantId('1001');
        $this->tool = new DocumentParseTool;
    }

    /**
     * 创建文件记录并写入 fake 磁盘
     */
    private function makeFile(string $filename, string $mime, string $content, int $tenantId = 1001): FileUpload
    {
        $path = "uploads/{$filename}";
        Storage::disk('local')->put($path, $content);

        return FileUpload::forceCreate([
            'file_upload_id' => random_int(1000000000000000, 9007199254740991),
            'tenant_id' => $tenantId,
            'disk' => 'local',
            'path' => $path,
            'filename' => $filename,
            'mime_type' => $mime,
            'size' => strlen($content),
        ]);
    }

    public function test_missing_file_id_returns_error(): void
    {
        $result = ($this->tool)([], 1001);

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('file_id', $result['message']);
    }

    public function test_unknown_file_returns_error(): void
    {
        $result = ($this->tool)(['file_id' => '999'], 1001);

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('未找到', $result['message']);
    }

    public function test_other_tenant_file_is_invisible(): void
    {
        $file = $this->makeFile('secret.txt', 'text/plain', '他租户内容', 1002);

        $result = ($this->tool)(['file_id' => (string) $file->file_upload_id], 1001);

        $this->assertTrue($result['error']);
    }

    public function test_parses_plain_text(): void
    {
        $file = $this->makeFile('plan.md', 'text/markdown', "# 活动策划\n主题：周年庆");

        $result = ($this->tool)(['file_id' => (string) $file->file_upload_id], 1001);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertEquals('text', $result['format']);
        $this->assertStringContainsString('周年庆', $result['content']);
        $this->assertFalse($result['truncated']);
    }

    public function test_parses_csv_as_text(): void
    {
        $file = $this->makeFile('customers.csv', 'text/csv', "姓名,电话\n张三,138");

        $result = ($this->tool)(['file_id' => (string) $file->file_upload_id], 1001);

        $this->assertEquals('text', $result['format']);
        $this->assertStringContainsString('张三', $result['content']);
    }

    public function test_parses_docx(): void
    {
        $tempPath = (string) tempnam(sys_get_temp_dir(), 'docx_test_');
        $zip = new ZipArchive;
        $zip->open($tempPath, ZipArchive::OVERWRITE);
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0"?><w:document><w:body><w:p><w:r><w:t>营销方案第一段</w:t></w:r></w:p><w:p><w:r><w:t>预算十万元</w:t></w:r></w:p></w:body></w:document>'
        );
        $zip->close();

        $file = $this->makeFile(
            'plan.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            (string) file_get_contents($tempPath),
        );
        @unlink($tempPath);

        $result = ($this->tool)(['file_id' => (string) $file->file_upload_id], 1001);

        $this->assertEquals('docx', $result['format']);
        $this->assertStringContainsString('营销方案第一段', $result['content']);
        $this->assertStringContainsString('预算十万元', $result['content']);
    }

    public function test_long_content_is_truncated(): void
    {
        $file = $this->makeFile('long.txt', 'text/plain', str_repeat('长文本内容。', 5000));

        $result = ($this->tool)(['file_id' => (string) $file->file_upload_id], 1001);

        $this->assertTrue($result['truncated']);
        $this->assertEquals(12000, mb_strlen($result['content']));
        $this->assertEquals(30000, $result['total_length']);
    }

    public function test_unsupported_type_returns_structured_error(): void
    {
        $file = $this->makeFile('video.mp4', 'video/mp4', 'binary');

        $result = ($this->tool)(['file_id' => (string) $file->file_upload_id], 1001);

        $this->assertTrue($result['error']);
        $this->assertStringContainsString('暂不支持', $result['message']);
    }
}
