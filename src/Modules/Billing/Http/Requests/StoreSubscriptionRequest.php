<?php

namespace MultiTenantSaas\Modules\Billing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan_id' => 'required|integer',
            'payment_method' => 'nullable|string',
            'auto_renew' => 'nullable|boolean',
        ];
    }
}
