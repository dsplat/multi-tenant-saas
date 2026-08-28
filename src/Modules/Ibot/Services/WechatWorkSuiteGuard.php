<?php

namespace MultiTenantSaas\Modules\Ibot\Services;

use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkSuiteService;

/**
 * 企微代开发套件授权守卫（9.3 ibot 双轨）
 *
 * WechatWork 模块为可选拆包（split 独立包），未安装/未迁移时按自建轨处理：
 * - authorized(): 租户是否存在有效套件授权（corp_secret 可省略的唯一判据）
 * - corpAccessToken(): 企业 token 解析（permanent_code 充当 secret）
 */
class WechatWorkSuiteGuard
{
    public static function authorized(int $tenantId): bool
    {
        if ($tenantId <= 0) {
            return false;
        }

        if (! class_exists(WechatWorkSuiteService::class) || ! Schema::hasTable('wechat_work_authorizations')) {
            return false;
        }

        $authorization = app(WechatWorkSuiteService::class)->authorization($tenantId);

        return $authorization !== null && $authorization->isAuthorized();
    }

    public static function corpAccessToken(int $tenantId): string
    {
        return app(WechatWorkSuiteService::class)->corpAccessToken($tenantId);
    }
}
