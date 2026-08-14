<?php

namespace MultiTenantSaas\Modules\Ai\Services\Tool;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;

/**
 * ask_user_choice — 向用户提问并给出可点选的选项按钮（单选/多选）
 *
 * 秘书需要用户确认或选择时（如"是否已完成 ICP 备案？"的是/否二选一，
 * 或多项方案单选/多选），调用本工具返回结构化载荷
 * {question, options, multiple}，由前端 AiAssistant 捕获后渲染
 * ChoiceCard 选项按钮，用户点选后选择结果作为用户消息回传对话。
 *
 * 纯结构化输出透传，不执行任何写操作；同 suggest_form_fill 模式：
 * 工具结果帧经 Node 引擎哑管道透传，前端按 result.action 识别渲染。
 */
class AskUserChoiceTool implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $question = trim((string) ($arguments['question'] ?? ''));
        $options = $arguments['options'] ?? [];

        if ($question === '') {
            return ['error' => true, 'message' => 'question 不能为空'];
        }

        if (! is_array($options)) {
            return ['error' => true, 'message' => 'options 必须是选项文案数组'];
        }

        // 归一化为非空字符串列表（去空白项并 trim）
        $options = array_values(array_filter(array_map(
            fn ($option) => trim((string) $option),
            $options,
        ), fn (string $option) => $option !== ''));

        if (count($options) < 2) {
            return ['error' => true, 'message' => 'options 至少需要 2 个有效选项'];
        }

        return [
            'action' => 'user_choice',
            'question' => $question,
            'options' => $options,
            'multiple' => (bool) ($arguments['multiple'] ?? false),
        ];
    }
}
