<?php

namespace MultiTenantSaas\Modules\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ModuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this['name'],
            'version' => $this['version'] ?? '0.0.0',
            'description' => $this['description'] ?? '',
            'status' => $this['status'] ?? 'unknown',
            'priority' => $this['priority'] ?? 100,
            'tenant_toggleable' => $this['tenant_toggleable'] ?? false,
            'dependencies' => $this['dependencies'] ?? [],
            'conflicts' => $this['conflicts'] ?? [],
        ];
    }
}
