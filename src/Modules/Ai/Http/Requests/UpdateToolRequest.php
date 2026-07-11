<?php

namespace MultiTenantSaas\Modules\Ai\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新工具请求验证（§6.4）
 */
class UpdateToolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:50',
            'parameters_schema' => 'nullable|array',
            'handler_class' => 'nullable|string|max:255',
            'enabled' => 'nullable|boolean',
        ];
    }
}
