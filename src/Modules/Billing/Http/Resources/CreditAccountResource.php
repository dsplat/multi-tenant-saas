<?php

namespace MultiTenantSaas\Modules\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreditAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'balance' => $this->balance,
            'total_recharged' => $this->total_recharged,
            'total_consumed' => $this->total_consumed,
            'updated_at' => $this->updated_at,
        ];
    }
}
