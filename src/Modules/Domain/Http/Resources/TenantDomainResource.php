<?php

namespace MultiTenantSaas\Modules\Domain\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantDomainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'type' => $this->type,
            'status' => $this->status,
            'verified_at' => $this->verified_at,
            'created_at' => $this->created_at,
        ];
    }
}
