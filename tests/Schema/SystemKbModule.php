<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 系统知识库模块
 * 表: system_kb_documents, system_kb_chunks
 */
class SystemKbModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('system_kb_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('document_id')->primary();
            $table->string('source', 30);
            $table->string('module', 100)->default('');
            $table->string('path', 500);
            $table->string('title', 255);
            $table->string('audience', 20)->default('operator');
            $table->string('locale', 10)->default('zh');
            $table->string('version', 50)->default('');
            $table->string('checksum', 64);
            $table->timestamps();

            $table->unique('path');
            $table->index(['source', 'module']);
        });

        Schema::create('system_kb_chunks', function (Blueprint $table) {
            $table->unsignedBigInteger('chunk_id')->primary();
            $table->unsignedBigInteger('document_id');
            $table->unsignedInteger('position')->default(0);
            $table->string('heading', 255)->default('');
            $table->text('content');
            $table->json('embedding')->nullable();
            $table->timestamps();

            $table->index('document_id');
        });
    }

    public function getTableNames(): array
    {
        return ['system_kb_documents', 'system_kb_chunks'];
    }
}
