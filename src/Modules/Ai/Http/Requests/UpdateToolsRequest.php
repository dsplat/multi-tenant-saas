<?php

namespace MultiTenantSaas\Modules\Ai\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateToolsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tool_slugs' => 'required|array|min:1',
            'tool_slugs.*' => 'string',
        ];
    }
}
