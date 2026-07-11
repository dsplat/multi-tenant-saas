<?php

namespace MultiTenantSaas\Modules\Ai\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 注册工具请求验证（§6.4）
 */
class RegisterToolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'slug' => 'required|string|max:100',
            'description' => 'required|string',
            'category' => 'nullable|string|max:50',
            'parameters_schema' => 'required|array',
            'handler_class' => 'required|string|max:255',
            'enabled' => 'nullable|boolean',
        ];
    }
}
