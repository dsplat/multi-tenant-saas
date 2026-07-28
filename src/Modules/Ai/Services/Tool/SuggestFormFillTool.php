<?php

namespace MultiTenantSaas\Modules\Ai\Services\Tool;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;

/**
 * suggest_form_fill — 返回结构化表单填充建议（只读结构化输出）
 *
 * 秘书"智能填表"能力：不直接提交表单，仅根据当前页面上下文
 * 返回 {fields, explanation, field_notes, confidence}，
 * 由前端 AiAssistant 捕获后渲染 FormFillCard，用户点"应用"才回填到页面。
 *
 * 该工具的 SSE 转换在 AssistantController::extractFormFill 完成；
 * 此 handler 返回值作为工具结果回传给 LLM，供其续答确认。
 */
class SuggestFormFillTool implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $fields = $arguments['fields'] ?? [];

        if (! is_array($fields) || $fields === []) {
            return ['error' => true, 'message' => 'fields 不能为空'];
        }

        return [
            'action' => 'form_fill',
            'fields' => $fields,
            'explanation' => trim((string) ($arguments['explanation'] ?? '')) ?: null,
            'field_notes' => is_array($arguments['field_notes'] ?? null) ? $arguments['field_notes'] : null,
            'confidence' => isset($arguments['confidence']) ? (float) $arguments['confidence'] : 0.8,
        ];
    }
}
