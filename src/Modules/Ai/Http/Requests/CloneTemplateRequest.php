<?php

namespace MultiTenantSaas\Modules\Ai\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloneTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'nullable|string|max:100',
            'avatar' => 'nullable|string|max:500',
            'description' => 'nullable|string',
            'tools' => 'nullable|array',
            'tools.*' => 'string',
            'kb_ids' => 'nullable|array',
            'kb_ids.*' => 'integer',
            'feature_keys' => 'nullable|array',
            'feature_keys.*' => 'string',
            'model_config' => 'nullable|array',
            'enabled' => 'nullable|boolean',
        ];
    }
}
