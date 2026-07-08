<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_tools', function (Blueprint $table) {
            $table->unsignedBigInteger('mcp_tool_id')->primary()->comment('MCP Tool ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('client_id')->comment('关联 MCP Client ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->string('name', 100)->comment('MCP Tool 名称');
            $table->text('description')->nullable()->comment('MCP Tool 描述');
            $table->json('input_schema')->nullable()->comment('输入参数 JSON Schema');
            $table->timestamps();

            $table->unique(['client_id', 'name']);
            $table->index(['tenant_id', 'client_id']);
            $table->foreign('client_id')->references('mcp_client_id')->on('mcp_clients')->onDelete('cascade');
            $table->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tools');
    }
};
