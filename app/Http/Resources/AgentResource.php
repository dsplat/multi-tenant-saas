<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'agent_id' => $this->agent_id,
            'name' => $this->name,
            'role' => $this->role,
            'avatar' => $this->avatar,
            'system_prompt' => $this->system_prompt,
            'description' => $this->description,
            'tools' => $this->tools,
            'kb_ids' => $this->kb_ids,
            'feature_keys' => $this->feature_keys,
            'model_config' => $this->model_config,
            'enabled' => $this->enabled,
            'is_builtin' => $this->is_builtin,
            'metadata' => $this->metadata,
            'version' => $this->version,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
