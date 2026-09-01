<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Live;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

/**
 * Live 模块（直播）
 *
 * 房间元数据 + provider 适配器（manual/polyv/tencent，凭证走租户/平台双轨设置）
 * + 挂课程复用权益 + 回放转化课程章节 + 弹幕回调落库。
 * 事件扩展点：LiveStarted / LiveEnded。
 */
class LiveServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'live';
}
