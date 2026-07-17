<?php

namespace MultiTenantSaas\Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->user_id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role?->name,
            'role_display_name' => $this->role?->display_name,
            'is_active' => $this->is_active,
            'joined_at' => $this->joined_at,
        ];
    }
}
