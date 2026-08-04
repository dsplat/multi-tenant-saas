<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\User\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 用户偏好设置
 *
 * 按 user_id 唯一（无租户维度），preferences 为 JSON 列。
 */
class UserPreference extends Model
{
    protected $table = 'user_preferences';

    protected $fillable = [
        'user_id',
        'preferences',
    ];

    protected function casts(): array
    {
        return [
            'preferences' => 'array',
        ];
    }
}
