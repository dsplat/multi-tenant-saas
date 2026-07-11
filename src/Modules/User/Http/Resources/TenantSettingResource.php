<?php

namespace MultiTenantSaas\Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantSettingResource extends JsonResource
{
    /**
     * 需要脱敏的 key（包含这些关键词的值会被 mask）
     */
    private const SENSITIVE_KEYS = ['secret', 'password', 'key', 'token', 'private'];

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'group' => $this->group,
            'key' => $this->key,
            'value' => $this->isSensitive() ? '********' : $this->value,
            'is_encrypted' => $this->is_encrypted,
        ];
    }

    private function isSensitive(): bool
    {
        $key = strtolower($this->key);

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($key, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
