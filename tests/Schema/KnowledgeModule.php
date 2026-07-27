<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 知识库模块
 * 表: external_kb_connections
 */
class KnowledgeModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('external_kb_connections', function (Blueprint $table) {
            $table->unsignedBigInteger('connection_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('provider_type', 30);
            $table->string('name', 100);
            $table->string('api_url', 500);
            $table->string('api_key_encrypted', 500)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_synced_at')->nullable();
            $table->json('config')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'provider_type']);
        });
    }

    public function getTableNames(): array
    {
        return ['external_kb_connections'];
    }
}
