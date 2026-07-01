<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_executions', function (Blueprint $table) {
            $table->unsignedBigInteger('execution_id')->primary()->comment('执行 ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('workflow_id')->comment('工作流 ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->string('status', 20)->default('pending')->comment('状态: pending/running/completed/failed/cancelled');
            $table->json('context')->nullable()->comment('执行上下文（JSON）');
            $table->text('error')->nullable()->comment('错误信息');
            $table->timestamp('started_at')->nullable()->comment('开始时间');
            $table->timestamp('completed_at')->nullable()->comment('完成时间');
            $table->timestamps();

            $table->index(['workflow_id', 'status']);
            $table->index(['tenant_id', 'status']);
            $table->foreign('workflow_id')->references('workflow_id')->on('workflows')->onDelete('cascade');
            $table->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_executions');
    }
};
