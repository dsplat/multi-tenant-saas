<?php

namespace MultiTenantSaas\Modules\Conversation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Agent 会话消息 JSON 资源
 */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'message_id' => $this->message_id,
            'conversation_id' => $this->conversation_id,
            'role' => $this->role,
            'content' => $this->content,
            'tool_calls' => $this->tool_calls,
            'tool_call_id' => $this->tool_call_id,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
        ];
    }
}
