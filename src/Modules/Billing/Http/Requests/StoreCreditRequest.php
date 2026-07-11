<?php

namespace MultiTenantSaas\Modules\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|integer|min:1',
            'description' => 'nullable|string|max:255',
        ];
    }
}
