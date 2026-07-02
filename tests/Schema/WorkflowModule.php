<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 工作流模块
 * 表: workflows, workflow_nodes, workflow_executions
 */
class WorkflowModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->unsignedBigInteger('workflow_id')->primary()->comment('工作流 ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->string('name', 100)->comment('工作流名称');
            $table->string('description', 500)->nullable()->comment('工作流描述');
            $table->string('type', 30)->default('sequential')->comment('类型');
            $table->string('status', 20)->default('draft')->comment('状态');
            $table->integer('version')->default(1)->comment('版本号');
            $table->json('config')->nullable()->comment('配置');
            $table->boolean('enabled')->default(true)->comment('是否启用');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'type']);
        });

        Schema::create('workflow_nodes', function (Blueprint $table) {
            $table->unsignedBigInteger('node_id')->primary()->comment('节点 ID');
            $table->unsignedBigInteger('workflow_id')->comment('所属工作流 ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->string('name', 100)->comment('节点名称');
            $table->string('type', 30)->comment('节点类型');
            $table->json('config')->nullable()->comment('节点配置');
            $table->unsignedBigInteger('next_node_id')->nullable()->comment('下一节点 ID');
            $table->integer('order')->default(0)->comment('排序');
            $table->timestamps();

            $table->index(['workflow_id', 'order']);
            $table->index('tenant_id');
        });

        Schema::create('workflow_executions', function (Blueprint $table) {
            $table->unsignedBigInteger('execution_id')->primary()->comment('执行 ID');
            $table->unsignedBigInteger('workflow_id')->comment('工作流 ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->string('status', 20)->default('pending')->comment('状态');
            $table->json('context')->nullable()->comment('执行上下文');
            $table->text('error')->nullable()->comment('错误信息');
            $table->timestamp('started_at')->nullable()->comment('开始时间');
            $table->timestamp('completed_at')->nullable()->comment('完成时间');
            $table->timestamps();

            $table->index(['workflow_id', 'status']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function getTableNames(): array
    {
        return ['workflows', 'workflow_nodes', 'workflow_executions'];
    }
}
