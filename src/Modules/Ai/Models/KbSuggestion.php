<?php

namespace MultiTenantSaas\Modules\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 系统知识库修改提案
 *
 * AI 小助手在生产端沉淀的知识缺口提案（只能提案不能定稿），
 * 由开发侧 secretary:kb:harvest 收割进代码仓 kb 文档。
 */
class KbSuggestion extends Model
{
    use SerializesFriendlyDates;
    use BelongsToTenant, HasGlobalId;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ADOPTED = 'adopted';

    public const STATUS_REJECTED = 'rejected';

    protected $table = 'kb_suggestions';

    protected $primaryKey = 'suggestion_id';

    protected $fillable = [
        'tenant_id', 'conversation_id', 'target_module', 'target_doc',
        'trigger_query', 'suggested_content', 'status', 'resolved_at',
    ];

    protected $casts = [
        'suggestion_id' => 'integer',
        'tenant_id' => 'integer',
        'conversation_id' => 'integer',
        'resolved_at' => 'datetime',
    ];
}
