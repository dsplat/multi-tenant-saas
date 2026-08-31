<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Live;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

/**
 * Live 模块（直播）
 *
 * 一期第三方 SaaS 集成：房间元数据 + provider 适配器（manual 落地，
 * polyun/tencent 留桩）+ 挂课程复用权益 + 回放转化课程章节。
 * 事件扩展点：LiveStarted / LiveEnded。
 */
class LiveServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'live';
}
