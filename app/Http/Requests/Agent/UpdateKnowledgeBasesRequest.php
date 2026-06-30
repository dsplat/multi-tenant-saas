<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

class UpdateKnowledgeBasesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kb_ids' => 'required|array|min:1',
            'kb_ids.*' => 'integer',
        ];
    }
}
