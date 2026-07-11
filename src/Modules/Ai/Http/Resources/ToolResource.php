<?php

namespace MultiTenantSaas\Modules\Ai\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Agent 工具 JSON 资源
 */
class ToolResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'tool_id' => $this->tool_id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'category' => $this->category,
            'parameters_schema' => $this->parameters_schema,
            'handler_class' => $this->handler_class,
            'enabled' => $this->enabled,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
