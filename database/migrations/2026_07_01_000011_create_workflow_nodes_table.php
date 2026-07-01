<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_nodes', function (Blueprint $table) {
            $table->unsignedBigInteger('node_id')->primary()->comment('节点 ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('workflow_id')->comment('所属工作流 ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->string('name', 100)->comment('节点名称');
            $table->string('type', 30)->comment('节点类型: start/end/condition/action/wait');
            $table->json('config')->nullable()->comment('节点配置（JSON）');
            $table->unsignedBigInteger('next_node_id')->nullable()->comment('下一节点 ID');
            $table->integer('order')->default(0)->comment('排序');
            $table->timestamps();

            $table->index(['workflow_id', 'order']);
            $table->index(['tenant_id']);
            $table->foreign('workflow_id')->references('workflow_id')->on('workflows')->onDelete('cascade');
            $table->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');
            $table->foreign('next_node_id')->references('node_id')->on('workflow_nodes')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_nodes');
    }
};
