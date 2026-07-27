<?php

namespace MultiTenantSaas\Modules\Knowledge\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 外部知识库连接（租户级）
 *
 * 每个租户可配置多个外部知识库连接（Dify/RAGFlow/FastGPT），
 * 未配置时由 ExternalKbService 回退到平台默认连接（system_settings group=external_kb）。
 */
class ExternalKbConnection extends Model
{
    use BelongsToTenant, HasGlobalId, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected $table = 'external_kb_connections';

    protected $primaryKey = 'connection_id';

    protected $fillable = [
        'tenant_id', 'provider_type', 'name', 'api_url',
        'api_key_encrypted', 'status', 'last_synced_at', 'config', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'config' => 'array',
            'metadata' => 'array',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * 设置 API Key（加密存储）
     */
    public function setApiKey(?string $apiKey): void
    {
        $this->api_key_encrypted = ($apiKey === null || $apiKey === '')
            ? null
            : Crypt::encryptString($apiKey);
    }

    /**
     * 获取解密后的 API Key
     */
    public function getApiKey(): ?string
    {
        if ($this->api_key_encrypted === null) {
            return null;
        }

        try {
            return Crypt::decryptString($this->api_key_encrypted);
        } catch (\Exception $e) {
            logger()->error('Failed to decrypt external kb api key', [
                'connection_id' => $this->connection_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 转为 Provider 可用的配置数组
     */
    public function toProviderConfig(): array
    {
        return array_merge($this->config ?? [], [
            'api_url' => $this->api_url,
            'api_key' => $this->getApiKey(),
        ]);
    }
}
