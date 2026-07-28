<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * kb_suggestions — 系统知识库修改提案（AI 自学习回流通道）
 *
 * 小秘书检索不到答案时经 suggest_kb_update（L2 确认）沉淀提案；
 * 开发侧 secretary:kb:harvest 收割合并进代码仓 kb 文档后标记 adopted。
 * 生产端 AI 只能"提案"，代码仓才能"定稿"。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kb_suggestions')) {
            return;
        }

        Schema::create('kb_suggestions', function (Blueprint $table) {
            $table->unsignedBigInteger('suggestion_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->string('target_module', 100)->default('')->comment('目标模块（kebab-case，可空=全局）');
            $table->string('target_doc', 200)->default('')->comment('目标文档 identity（如 customer/usage.md，空=建议新文档）');
            $table->string('trigger_query', 500)->comment('触发提案的用户原始问题（知识缺口证据）');
            $table->text('suggested_content')->comment('建议补充的知识内容（markdown）');
            $table->string('status', 20)->default('pending')->comment('pending/adopted/rejected');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_suggestions');
    }
};
