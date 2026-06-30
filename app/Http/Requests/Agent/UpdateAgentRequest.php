<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:100',
            'role' => 'sometimes|string|max:50',
            'avatar' => 'nullable|string|max:500',
            'system_prompt' => 'sometimes|string',
            'description' => 'nullable|string',
            'tools' => 'nullable|array',
            'tools.*' => 'string',
            'kb_ids' => 'nullable|array',
            'kb_ids.*' => 'integer',
            'feature_keys' => 'nullable|array',
            'feature_keys.*' => 'string',
            'model_config' => 'nullable|array',
            'enabled' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ];
    }
}
