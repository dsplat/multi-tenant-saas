<?php

namespace MultiTenantSaas\Modules\Ai\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 在已有会话中发消息请求校验
 *
 * 用于 POST /api/v1/agents/{id}/chat/{conversation_id} 端点
 */
class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => 'required|string|max:32000',
            'options' => 'nullable|array',
            'options.max_tool_calls' => 'nullable|integer|min:1',
            'options.temperature' => 'nullable|numeric|min:0|max:2',
        ];
    }
}
