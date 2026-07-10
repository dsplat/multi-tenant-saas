<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_clients', function (Blueprint $table) {
            $table->unsignedBigInteger('mcp_client_id')->primary();
            $table->string('slug', 64)->unique()->comment('客户端标识');
            $table->string('name')->comment('客户端名称');
            $table->string('output_format', 32)->default('json_config')->comment('输出格式: markdown_skill/json_config');
            $table->string('description')->nullable()->comment('描述');
            $table->boolean('is_enabled')->default(true)->comment('是否启用');
            $table->json('config')->nullable()->comment('客户端配置');
            $table->timestamps();

            $table->index('slug');
            $table->index('is_enabled');
        });

        Schema::create('mcp_client_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('mcp_client_token_id')->primary();
            $table->unsignedBigInteger('mcp_client_id')->comment('关联客户端');
            $table->unsignedBigInteger('tenant_id')->nullable()->comment('关联租户');
            $table->string('token', 64)->unique()->comment('SHA256 哈希后的 Token');
            $table->string('token_plain', 128)->nullable()->comment('明文 Token（仅创建时返回）');
            $table->json('abilities')->nullable()->comment('权限列表');
            $table->timestamp('expires_at')->nullable()->comment('过期时间');
            $table->boolean('is_active')->default(true)->comment('是否启用');
            $table->timestamp('last_used_at')->nullable()->comment('最后使用时间');
            $table->unsignedInteger('last_used_count')->default(0)->comment('累计使用次数');
            $table->timestamps();

            $table->foreign('mcp_client_id')->references('mcp_client_id')->on('mcp_clients')->onDelete('cascade');
            $table->index('mcp_client_id');
            $table->index('tenant_id');
            $table->index('is_active');
        });

        Schema::create('mcp_tool_access_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('log_id')->primary();
            $table->unsignedBigInteger('mcp_client_id')->nullable()->comment('调用客户端');
            $table->unsignedBigInteger('mcp_client_token_id')->nullable()->comment('使用的 Token');
            $table->unsignedBigInteger('tenant_id')->nullable()->comment('租户 ID');
            $table->string('tool_name', 128)->comment('工具名称');
            $table->json('arguments')->nullable()->comment('调用参数');
            $table->json('result')->nullable()->comment('返回结果');
            $table->string('status', 20)->default('success')->comment('状态: success/error');
            $table->unsignedInteger('duration_ms')->nullable()->comment('执行时长(毫秒)');
            $table->string('ip_address', 45)->nullable()->comment('请求 IP');
            $table->string('user_agent')->nullable()->comment('User Agent');
            $table->timestamps();

            $table->index('mcp_client_id');
            $table->index('tenant_id');
            $table->index('tool_name');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tool_access_logs');
        Schema::dropIfExists('mcp_client_tokens');
        Schema::dropIfExists('mcp_clients');
    }
};
