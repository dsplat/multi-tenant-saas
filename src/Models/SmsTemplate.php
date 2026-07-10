<?php

namespace MultiTenantSaas\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\HasGlobalId;

/**
 * 短信模板模型
 *
 * 营销短信模板管理。
 */
class SmsTemplate extends Model
{
    use HasFactory, HasGlobalId;

    protected $primaryKey = 'template_id';

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'content',
        'type',
        'sign_name',
        'params',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'metadata' => 'array',
        ];
    }
}
