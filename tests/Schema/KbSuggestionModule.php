<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 知识库提案模块
 * 表: kb_suggestions
 */
class KbSuggestionModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('kb_suggestions', function (Blueprint $table) {
            $table->unsignedBigInteger('suggestion_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->string('target_module', 100)->default('');
            $table->string('target_doc', 200)->default('');
            $table->string('trigger_query', 500);
            $table->text('suggested_content');
            $table->string('status', 20)->default('pending');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('status');
        });
    }

    public function getTableNames(): array
    {
        return ['kb_suggestions'];
    }
}
