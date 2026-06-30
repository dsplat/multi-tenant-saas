<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('request_id')->primary()->comment('请求ID（全局ID，16位数字）');
            $table->bigInteger('tenant_id')->unsigned()->nullable()->comment('租户ID，实现租户隔离');
            $table->bigInteger('user_id')->unsigned()->nullable()->comment('用户ID');
            $table->string('model', 100)->comment('模型名（对应 AiModelEnum 值或自定义模型）');
            $table->string('provider', 50)->comment('提供商标识');
            $table->text('prompt_summary')->nullable()->comment('请求内容摘要');
            $table->unsignedInteger('input_tokens')->default(0)->comment('输入 Token 用量');
            $table->unsignedInteger('output_tokens')->default(0)->comment('输出 Token 用量');
            $table->unsignedInteger('response_time_ms')->nullable()->comment('响应时间（毫秒）');
            $table->decimal('cost', 12, 6)->default(0)->comment('费用');
            $table->string('status', 20)->default('pending')->comment('状态: pending/success/failed');
            $table->text('error_message')->nullable()->comment('错误信息（失败时）');
            $table->json('metadata')->nullable()->comment('扩展元数据（finish_reason、options 摘要等）');
            $table->timestamps();

            $table->index(['tenant_id', 'created_at'], 'idx_tenant_created');
            $table->index(['tenant_id', 'model'], 'idx_tenant_model');
            $table->index(['tenant_id', 'provider'], 'idx_tenant_provider');
            $table->index('user_id', 'idx_user');
            $table->index(['tenant_id', 'status'], 'idx_tenant_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
