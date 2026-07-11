<?php

namespace MultiTenantSaas\Modules\Coupon\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Z0-9]+$/'],
            'prefix' => ['nullable', 'string', 'max:8'],
            'description' => ['nullable', 'string', 'max:512'],
            'type' => ['required', 'string', 'in:fixed,percentage'],
            'value' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'applies_to' => ['sometimes', 'string', 'in:subscription,all'],
            'subscription_plan_id' => ['nullable', 'integer'],
            'duration_months' => ['nullable', 'integer', 'min:1'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'max_uses_per_tenant' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => '优惠券类型不能为空',
            'type.in' => '优惠券类型只能是 fixed 或 percentage',
            'value.required' => '优惠券值不能为空',
            'value.min' => '优惠券值不能小于0',
            'code.regex' => '优惠码只能包含大写字母和数字',
        ];
    }
}
