<?php

namespace MultiTenantSaas\Modules\SSL\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantSslResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain' => $this->domain,
            'status' => $this->status,
            'expires_at' => $this->expires_at,
            'auto_renew' => $this->auto_renew,
            'created_at' => $this->created_at,
        ];
    }
}
