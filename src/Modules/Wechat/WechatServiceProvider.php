<?php

namespace MultiTenantSaas\Modules\Wechat;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

class WechatServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'wechat';

    protected function registerModuleBindings(): void
    {
        //
    }

    protected function bootModule(): void
    {
        $this->registerComponentCallbackRoutes();
    }

    /**
     * 微信第三方平台组件回调路由（裸路由，无中间件链）
     *
     * 微信第三方平台回调 URL 必须公网可访问，且回调请求不携带租户上下文
     * （Host 为平台统一回调域 auth.neihang.com）：
     * - GET：URL 有效性验证（echostr 验签解密）
     * - POST：事件推送（component_verify_ticket 每 10 分钟 / authorized /
     *   updateauthorized / unauthorized）
     * - authorize-callback：授权页完成后的平台域回跳（auth_code 换授权入库）
     * 控制器内按 component_appid 手动解析组件凭证，参照 WechatWork 模块
     * suite.php 的裸路由注册先例。
     */
    protected function registerComponentCallbackRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $moduleDir = dirname((new \ReflectionClass($this))->getFileName());
        $path = $moduleDir . '/Routes/callback.php';

        if (file_exists($path)) {
            $this->loadRoutesFrom($path);
        }
    }
}
