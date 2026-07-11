<?php

namespace MultiTenantSaas\Modules\Notification\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'read_at' => $this->read_at,
            'data' => $this->data,
            'created_at' => $this->created_at,
        ];
    }
}
