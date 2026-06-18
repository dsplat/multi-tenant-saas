<?php

namespace MultiTenantSaas\Concerns;

use MultiTenantSaas\Services\IdGenerator;

/**
 * 全局ID Trait
 *
 * 为模型提供16位随机数字ID支持
 * - 完全无序
 * - JavaScript安全
 * - 全局唯一（所有表共用ID空间）
 */
trait HasGlobalId
{
    /**
     * 启动时自动生成全局ID
     */
    protected static function bootHasGlobalId(): void
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = app(IdGenerator::class)->generate();
            }
        });
    }

    /**
     * 指示模型的ID不是自增的
     */
    public function getIncrementing(): bool
    {
        return false;
    }

    /**
     * 指示模型键的类型为整数
     */
    public function getKeyType(): string
    {
        return 'int';
    }
}
