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
            'status' => 'sometimes|in:active,suspended,inactive',
            'subscription_plan' => 'sometimes|in:free,basic,pro,enterprise',
            'domain' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:20',
            // 品牌信息（租户自助配置：Logo / 主色调 / 登录页文案）
            'logo' => 'nullable|string|max:500',
            'branding' => 'sometimes|array',
            'branding.primary_color' => 'nullable|string|max:20',
            'branding.secondary_color' => 'nullable|string|max:20',
            'branding.login_page_message' => 'nullable|string|max:500',
        ];
    }
}
