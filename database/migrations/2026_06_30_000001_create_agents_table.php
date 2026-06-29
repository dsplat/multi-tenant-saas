<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_id')->primary()->comment('Agent ID（全局ID）');
            $table->unsignedBigInteger('tenant_id')->nullable()->comment('租户ID');
            $table->string('name', 100)->comment('Agent 名称');
            $table->string('role', 50)->comment('角色标识');
            $table->string('avatar', 500)->nullable()->comment('头像 URL');
            $table->text('system_prompt')->comment('系统提示词');
            $table->text('description')->nullable()->comment('描述');
            $table->json('tools')->nullable()->comment('工具 slug 列表');
            $table->json('kb_ids')->nullable()->comment('知识库 ID 列表');
            $table->json('feature_keys')->nullable()->comment('AI 功能点列表（业务层使用）');
            $table->json('model_config')->nullable()->comment('模型配置 JSON');
            $table->boolean('enabled')->default(true)->comment('是否启用');
            $table->boolean('is_builtin')->default(false)->comment('是否内置');
            $table->json('metadata')->nullable()->comment('元数据');
            $table->integer('version')->default(1)->comment('版本号');
            $table->timestamps();

            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'role']);
            $table->index(['tenant_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
