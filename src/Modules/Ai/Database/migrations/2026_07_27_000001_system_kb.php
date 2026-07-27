<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 系统知识库文档表（平台级静态资产，无 tenant_id——全租户共享同一套文档）
        if (! Schema::hasTable('system_kb_documents')) {
            Schema::create('system_kb_documents', function (Blueprint $table) {
                $table->unsignedBigInteger('document_id')->primary();
                $table->string('source', 30)->comment('来源：framework/framework_module/vendor/project_module/project');
                $table->string('module', 100)->default('')->comment('所属模块（kebab-case，全局文档为空）');
                $table->string('path', 500)->comment('相对仓库根的文件路径');
                $table->string('title', 255)->comment('文档标题（frontmatter title 或首个 H1）');
                $table->string('audience', 20)->default('operator')->comment('受众：operator/internal，internal 不进租户可见检索');
                $table->string('locale', 10)->default('zh')->comment('语料语言');
                $table->string('version', 50)->default('')->comment('文档版本（frontmatter version）');
                $table->string('checksum', 64)->comment('内容 sha256，用于增量同步判定');
                $table->timestamps();

                $table->unique('path');
                $table->index(['source', 'module']);
            });
        }

        // 系统知识库分块表（按标题分块，embedding 为 JSON 向量，可空=纯关键词降级）
        if (! Schema::hasTable('system_kb_chunks')) {
            Schema::create('system_kb_chunks', function (Blueprint $table) {
                $table->unsignedBigInteger('chunk_id')->primary();
                $table->unsignedBigInteger('document_id')->comment('所属文档');
                $table->unsignedInteger('position')->default(0)->comment('文档内顺序');
                $table->string('heading', 255)->default('')->comment('分块标题（章节路径）');
                $table->text('content')->comment('分块正文');
                $table->json('embedding')->nullable()->comment('向量（embedding 不可用时为 null，fail-open 走关键词）');
                $table->timestamps();

                $table->index('document_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('system_kb_chunks');
        Schema::dropIfExists('system_kb_documents');
    }
};
