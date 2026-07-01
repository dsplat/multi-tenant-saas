<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->unsignedBigInteger('workflow_id')->primary()->comment('工作流 ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->string('name', 100)->comment('工作流名称');
            $table->string('description', 500)->nullable()->comment('工作流描述');
            $table->string('type', 30)->default('sequential')->comment('类型: sequential/parallel/conditional');
            $table->string('status', 20)->default('draft')->comment('状态: draft/active/archived');
            $table->integer('version')->default(1)->comment('版本号');
            $table->json('config')->nullable()->comment('工作流配置（JSON）');
            $table->boolean('enabled')->default(true)->comment('是否启用');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'type']);
            $table->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflows');
    }
};
