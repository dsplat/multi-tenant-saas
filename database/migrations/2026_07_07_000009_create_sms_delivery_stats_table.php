<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_delivery_stats', function (Blueprint $table) {
            $table->unsignedBigInteger('stat_id')->primary()->comment('统计ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->unsignedBigInteger('sms_batch_task_id')->comment('批量任务ID');
            $table->unsignedInteger('sent_count')->default(0)->comment('已发送数');
            $table->unsignedInteger('delivered_count')->default(0)->comment('到达数');
            $table->unsignedInteger('failed_count')->default(0)->comment('失败数');
            $table->unsignedInteger('clicked_count')->default(0)->comment('点击数');
            $table->unsignedInteger('unsubscribed_count')->default(0)->comment('退订数');
            $table->decimal('delivery_rate', 5, 2)->default(0)->comment('到达率');
            $table->timestamp('recorded_at')->comment('统计时间');
            $table->timestamps();

            $table->index(['tenant_id', 'sms_batch_task_id']);
            $table->index('recorded_at');

            $table->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');
            $table->foreign('sms_batch_task_id')->references('batch_task_id')->on('sms_batch_tasks')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_delivery_stats');
    }
};
