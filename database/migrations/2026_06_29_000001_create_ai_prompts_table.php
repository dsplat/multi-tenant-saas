<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Contracts\IdGeneratorContract;

/**
 * ai_prompts 表迁移
 *
 * 提示词模板表：存储系统级（tenant_id 为 null）与租户级提示词模板，
 * 支持分类、变量占位符、版本号与状态管理。迁移执行时预置 4 个系统级模板。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prompts', function (Blueprint $table) {
            $table->unsignedBigInteger('prompt_id')->primary()->comment('提示词ID（全局ID，16位数字）');
            $table->bigInteger('tenant_id')->unsigned()->nullable()->comment('租户ID，null 表示系统级模板');
            $table->string('name', 100)->comment('模板名称（同租户内唯一，租户可同名覆盖系统级）');
            $table->string('category', 50)->default('general')->comment('分类');
            $table->text('system_prompt')->nullable()->comment('系统提示词');
            $table->text('user_prompt')->nullable()->comment('用户提示词模板（含 {{变量}} 占位符）');
            $table->json('variables')->nullable()->comment('变量定义 JSON：[{name,description,required}]');
            $table->unsignedInteger('version')->default(1)->comment('版本号');
            $table->string('status', 20)->default('active')->comment('状态: active/inactive');
            $table->timestamps();

            $table->index(['tenant_id', 'name'], 'idx_tenant_name');
            $table->index('category', 'idx_category');
            $table->index('status', 'idx_ai_prompts_status');
        });

        $this->seedSystemPrompts();
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompts');
    }

    /**
     * 预置 4 个系统级提示词模板：通用助手、客服助手、代码助手、翻译助手
     */
    protected function seedSystemPrompts(): void
    {
        $idGenerator = app(IdGeneratorContract::class);
        $now = now();

        $presets = [
            [
                'name' => 'general_assistant',
                'category' => 'assistant',
                'system_prompt' => '你是一个通用助手，擅长回答各类问题、提供信息与建议。请用简洁、清晰、有条理的方式回复。',
                'user_prompt' => '{{input}}',
                'variables' => json_encode([
                    ['name' => 'input', 'description' => '用户输入', 'required' => true],
                ]),
            ],
            [
                'name' => 'customer_service',
                'category' => 'service',
                'system_prompt' => '你是一名专业的客服助手，负责解答用户咨询、处理售后问题。请保持礼貌、耐心，给出准确且可执行的解决方案。',
                'user_prompt' => '{{question}}',
                'variables' => json_encode([
                    ['name' => 'question', 'description' => '用户咨询问题', 'required' => true],
                ]),
            ],
            [
                'name' => 'code_assistant',
                'category' => 'development',
                'system_prompt' => '你是一名资深工程师，擅长代码编写、审查与调试。请给出符合最佳实践的代码示例，并附简要说明。',
                'user_prompt' => '{{task}}',
                'variables' => json_encode([
                    ['name' => 'task', 'description' => '编程任务描述', 'required' => true],
                ]),
            ],
            [
                'name' => 'translation_assistant',
                'category' => 'language',
                'system_prompt' => '你是一名专业翻译，支持多语言互译。请保持译文准确、自然、贴合语境。如遇歧义，给出备选译法。',
                'user_prompt' => "请将以下{{source_lang}}文本翻译为{{target_lang}}：\n\n{{text}}",
                'variables' => json_encode([
                    ['name' => 'source_lang', 'description' => '源语言', 'required' => true],
                    ['name' => 'target_lang', 'description' => '目标语言', 'required' => true],
                    ['name' => 'text', 'description' => '待翻译文本', 'required' => true],
                ]),
            ],
        ];

        $rows = [];
        foreach ($presets as $preset) {
            $rows[] = array_merge([
                'prompt_id' => $idGenerator->generate(),
                'tenant_id' => null,
                'version' => 1,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ], $preset);
        }

        DB::table('ai_prompts')->insert($rows);
    }
};
