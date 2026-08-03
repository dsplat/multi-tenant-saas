<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 内容包⇄内容关联（复合主键，仅排序承载）
 */
class PlatformContentPackItem extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $fillable = [
        'pack_id',
        'content_id',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'pack_id' => 'integer',
            'content_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
