<?php

namespace MultiTenantSaas\Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 系统知识库分块（按标题分块的检索单元）
 *
 * embedding 为 JSON 向量数组；embedding 服务不可用时为 null，
 * 检索侧 fail-open 降级为纯关键词匹配。
 */
class SystemKbChunk extends Model
{
    use HasGlobalId;

    protected $table = 'system_kb_chunks';

    protected $primaryKey = 'chunk_id';

    protected $fillable = [
        'document_id',
        'position',
        'heading',
        'content',
        'embedding',
    ];

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'position' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(SystemKbDocument::class, 'document_id', 'document_id');
    }
}
