<?php

namespace MultiTenantSaas\Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 系统知识库文档（平台级静态资产）
 *
 * 全租户共享同一套系统文档，无 tenant_id。
 * 由 SystemKbRegistry 发现、SystemKbIndexer 落库。
 */
class SystemKbDocument extends Model
{
    use HasGlobalId;

    protected $table = 'system_kb_documents';

    protected $primaryKey = 'document_id';

    protected $fillable = [
        'source',
        'module',
        'path',
        'title',
        'audience',
        'locale',
        'version',
        'checksum',
    ];

    public function chunks(): HasMany
    {
        return $this->hasMany(SystemKbChunk::class, 'document_id', 'document_id');
    }
}
