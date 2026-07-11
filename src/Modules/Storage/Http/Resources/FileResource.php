<?php

namespace MultiTenantSaas\Modules\Storage\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'path' => $this->path,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'url' => $this->url,
            'created_at' => $this->created_at,
        ];
    }
}
