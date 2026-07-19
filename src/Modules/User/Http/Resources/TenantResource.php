<?php

namespace MultiTenantSaas\Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MultiTenantSaas\Modules\Operator\Models\Operator;

class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'domain' => $this->domain,
            'custom_domain' => $this->custom_domain,
            'logo' => $this->logo,
            'description' => $this->description,
            'status' => $this->status,
            'subscription_plan' => $this->subscription_plan,
            'subscription_expires_at' => $this->subscription_expires_at,
            'available_credits' => $this->available_credits,
            'contact_name' => $this->contact_name,
            'contact_email' => $this->when(
                $this->isPlatformOperator($request),
                fn () => $this->contact_email
            ),
            'contact_phone' => $this->when(
                $this->isPlatformOperator($request),
                fn () => $this->contact_phone ? $this->maskPhone($this->contact_phone) : null
            ),
            'created_at' => $this->created_at,
        ];
    }

    private function isPlatformOperator(Request $request): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }

        // Operator 直连路径：仅 scope=platform 的 Operator 才是平台运营人员
        return $user instanceof Operator
            && $user->scope === 'platform';
    }

    private function maskPhone(string $phone): string
    {
        return substr($phone, 0, 3) . '****' . substr($phone, -4);
    }
}
