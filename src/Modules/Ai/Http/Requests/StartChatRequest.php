<?php

namespace MultiTenantSaas\Modules\Ai\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 发起对话请求校验
 *
 * 用于 POST /api/v1/agents/{id}/chat 端点
 */
class StartChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => 'required|string|max:32000',
            'customer_id' => 'nullable|integer',
            'staff_id' => 'nullable|integer',
            'channel' => 'nullable|string|max:20',
            'subject' => 'nullable|string|max:255',
            'options' => 'nullable|array',
            'options.max_tool_calls' => 'nullable|integer|min:1',
            'options.temperature' => 'nullable|numeric|min:0|max:2',
        ];
    }
}
