<?php

namespace MultiTenantSaas\Modules\Platform\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'group' => $this->group,
            'description' => $this->description,
            'updated_at' => $this->updated_at,
        ];
    }
}
