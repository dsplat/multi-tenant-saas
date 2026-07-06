<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_clients', function (Blueprint $table) {
            $table->unsignedBigInteger('mcp_client_id')->primary()->comment('MCP客户端ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->string('name', 100)->comment('客户端名称');
            $table->string('slug', 100)->comment('客户端标识');
            $table->string('type', 30)->default('custom')->comment('类型: workbuddy/hermes/openclaw/custom');
            $table->json('config')->nullable()->comment('配置');
            $table->string('status', 20)->default('active')->comment('状态: active/inactive');
            $table->integer('rate_limit')->default(100)->comment('速率限制');
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_clients');
    }
};
