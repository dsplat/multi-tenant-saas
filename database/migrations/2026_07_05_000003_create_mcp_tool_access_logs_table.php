<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_tool_access_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('mcp_tool_access_log_id')->primary()->comment('MCP工具访问日志ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->unsignedBigInteger('mcp_client_id')->comment('MCP客户端ID');
            $table->string('tool_slug', 100)->comment('工具标识');
            $table->string('request_id', 100)->nullable()->comment('请求ID');
            $table->json('input_summary')->nullable()->comment('输入摘要');
            $table->string('status', 20)->default('success')->comment('状态: success/error/timeout');
            $table->integer('duration_ms')->default(0)->comment('耗时(毫秒)');
            $table->text('error_message')->nullable()->comment('错误信息');
            $table->timestamp('created_at')->nullable()->comment('创建时间');

            $table->index(['tenant_id', 'mcp_client_id']);
            $table->index(['tenant_id', 'tool_slug']);
            $table->index('status');
            $table->index('created_at');
            $table->foreign('mcp_client_id')->references('mcp_client_id')->on('mcp_clients')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tool_access_logs');
    }
};
