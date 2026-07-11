<?php

namespace MultiTenantSaas\Modules\Ai\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'role' => 'required|string|max:50',
            'avatar' => 'nullable|string|max:500',
            'system_prompt' => 'required|string',
            'description' => 'nullable|string',
            'tools' => 'nullable|array',
            'tools.*' => 'string',
            'kb_ids' => 'nullable|array',
            'kb_ids.*' => 'integer',
            'feature_keys' => 'nullable|array',
            'feature_keys.*' => 'string',
            'model_config' => 'nullable|array',
            'model_config.preferred_provider' => 'nullable|string',
            'model_config.preferred_model' => 'nullable|string',
            'model_config.fallback_provider' => 'nullable|string',
            'model_config.fallback_model' => 'nullable|string',
            'model_config.temperature' => 'nullable|numeric|min:0|max:2',
            'model_config.max_tokens' => 'nullable|integer|min:1',
            'model_config.max_tool_calls' => 'nullable|integer|min:1',
            'model_config.stream' => 'nullable|boolean',
            'enabled' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ];
    }
}
