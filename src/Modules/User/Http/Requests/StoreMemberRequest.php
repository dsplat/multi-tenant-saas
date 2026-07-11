<?php

namespace MultiTenantSaas\Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'role' => 'required|string|in:tenant_admin,end_user',
            'credits' => 'nullable|integer|min:0',
        ];
    }
}
