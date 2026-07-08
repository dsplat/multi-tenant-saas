<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_clients', function (Blueprint $table) {
            $table->unsignedBigInteger('mcp_client_id')->primary()->comment('MCP Client ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->string('name', 100)->comment('MCP Client 名称');
            $table->string('base_url', 255)->comment('MCP Server 基地址');
            $table->text('api_key')->nullable()->comment('MCP API Key（加密存储）');
            $table->string('status', 20)->default('active')->comment('状态: active/inactive');
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'status']);
            $table->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_clients');
    }
};
