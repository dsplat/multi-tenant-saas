<?php

namespace MultiTenantSaas\Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->user_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->when($this->phone, fn () => $this->maskPhone($this->phone)),
            'role' => $this->role,
            'avatar' => $this->avatar,
            'created_at' => $this->created_at,
        ];
    }

    private function maskPhone(string $phone): string
    {
        return substr($phone, 0, 3) . '****' . substr($phone, -4);
    }
}
