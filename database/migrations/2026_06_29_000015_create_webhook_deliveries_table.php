<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->unsignedBigInteger('webhook_delivery_id')->primary();
            $table->unsignedBigInteger('webhook_id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('event_type', 100); // 触发的事件类型
            $table->json('payload'); // 请求体
            $table->unsignedSmallInteger('response_status_code')->nullable(); // 响应状态码
            $table->text('response_body')->nullable(); // 响应体
            $table->unsignedInteger('duration_ms')->nullable(); // 耗时（毫秒）
            $table->unsignedTinyInteger('attempts')->default(0); // 重试次数
            $table->string('status', 20)->default('pending'); // pending / delivered / failed
            $table->text('error_message')->nullable(); // 错误信息
            $table->timestamps();

            $table->index('webhook_id');
            $table->index('tenant_id');
            $table->index(['webhook_id', 'status']);
            $table->index(['tenant_id', 'status']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
