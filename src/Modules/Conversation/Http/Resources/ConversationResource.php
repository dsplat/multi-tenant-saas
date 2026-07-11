<?php

namespace MultiTenantSaas\Modules\Conversation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Agent 会话 JSON 资源
 */
class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'conversation_id' => $this->conversation_id,
            'agent_id' => $this->agent_id,
            'customer_id' => $this->customer_id,
            'staff_id' => $this->staff_id,
            'channel' => $this->channel,
            'subject' => $this->subject,
            'status' => $this->status,
            'summary' => $this->summary,
            'token_usage' => $this->token_usage,
            'message_count' => $this->message_count,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
