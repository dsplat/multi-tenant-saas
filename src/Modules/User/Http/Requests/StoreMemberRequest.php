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
            'user_id' => 'required',
            'role_id' => 'required|exists:roles,role_id',
        ];
    }
}
