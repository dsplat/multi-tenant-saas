<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_client_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('mcp_client_token_id')->primary()->comment('MCP客户端令牌ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->unsignedBigInteger('mcp_client_id')->comment('MCP客户端ID');
            $table->string('token', 255)->comment('令牌');
            $table->string('name', 100)->comment('令牌名称');
            $table->json('abilities')->nullable()->comment('权限');
            $table->timestamp('last_used_at')->nullable()->comment('最后使用时间');
            $table->timestamp('expires_at')->nullable()->comment('过期时间');
            $table->timestamps();

            $table->unique('token');
            $table->index(['tenant_id', 'mcp_client_id']);
            $table->index('expires_at');
            $table->foreign('mcp_client_id')->references('mcp_client_id')->on('mcp_clients')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_client_tokens');
    }
};
