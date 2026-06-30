<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

class UpdateModelConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'model_config' => 'required|array',
            'model_config.preferred_provider' => 'nullable|string',
            'model_config.preferred_model' => 'nullable|string',
            'model_config.fallback_provider' => 'nullable|string',
            'model_config.fallback_model' => 'nullable|string',
            'model_config.temperature' => 'nullable|numeric|min:0|max:2',
            'model_config.max_tokens' => 'nullable|integer|min:1',
            'model_config.max_tool_calls' => 'nullable|integer|min:1',
            'model_config.stream' => 'nullable|boolean',
        ];
    }
}
