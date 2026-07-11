<?php

namespace MultiTenantSaas\Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'contact_email' => 'nullable|email',
            'status' => 'sometimes|string|in:active,suspended',
        ];
    }
}
