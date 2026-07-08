<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_batch_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('batch_task_id')->primary()->comment('批量任务ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->unsignedBigInteger('sms_template_id')->comment('短信模板ID');
            $table->string('type', 20)->default('batch_send')->comment('类型: batch_send/scheduled');
            $table->string('target_type', 20)->default('user_list')->comment('目标类型: all_users/user_list/segment');
            $table->json('target_ids')->nullable()->comment('目标用户ID列表或分段条件');
            $table->string('phone_column', 50)->default('phone')->comment('手机号字段名');
            $table->unsignedInteger('total_count')->default(0)->comment('总数');
            $table->unsignedInteger('success_count')->default(0)->comment('成功数');
            $table->unsignedInteger('fail_count')->default(0)->comment('失败数');
            $table->string('status', 20)->default('pending')->comment('状态: pending/processing/completed/failed/cancelled');
            $table->timestamp('scheduled_at')->nullable()->comment('定时发送时间');
            $table->timestamp('started_at')->nullable()->comment('开始执行时间');
            $table->timestamp('completed_at')->nullable()->comment('完成时间');
            $table->text('error_log')->nullable()->comment('错误日志');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'sms_template_id']);
            $table->index('scheduled_at');

            $table->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');
            $table->foreign('sms_template_id')->references('sms_template_id')->on('sms_templates')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_batch_tasks');
    }
};
