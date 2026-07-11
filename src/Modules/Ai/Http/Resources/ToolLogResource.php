<?php

namespace MultiTenantSaas\Modules\Ai\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 工具调用日志 JSON 资源
 */
class ToolLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'log_id' => $this->log_id,
            'conversation_id' => $this->conversation_id,
            'agent_id' => $this->agent_id,
            'tool_name' => $this->tool_name,
            'input' => $this->input,
            'output' => $this->output,
            'duration_ms' => $this->duration_ms,
            'status' => $this->status,
            'error' => $this->error,
            'created_at' => $this->created_at,
        ];
    }
}
