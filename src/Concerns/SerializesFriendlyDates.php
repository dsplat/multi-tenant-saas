<?php

namespace MultiTenantSaas\Concerns;

use DateTimeInterface;

/**
 * 友好日期序列化
 *
 * 模型 JSON 序列化时日期统一输出 Y-m-d H:i:s（如 2026-08-17 07:42:14），
 * 替代 Laravel 默认的 ISO8601 带微秒格式（2026-08-17T07:42:14.000000Z），
 * 保证全项目所有展示时间日期的地方拿到人类可读格式。
 *
 * 注意：前端若需将该字符串转 Date 对象，Safari 不认空格分隔格式，
 * 应先 replace(' ', 'T') 再 new Date() / Date.parse()。
 */
trait SerializesFriendlyDates
{
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }
}
