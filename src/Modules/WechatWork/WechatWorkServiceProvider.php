<?php

namespace MultiTenantSaas\Modules\WechatWork;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;

class WechatWorkServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'wechatwork';

    protected function registerModuleBindings(): void
    {
        //
    }

    protected function bootModule(): void
    {
        $this->registerSuiteCallbackRoutes();
    }

    /**
     * 企微服务商套件回调路由（裸路由，无中间件链）
     *
     * 企微模板回调 URL 必须公网可访问，且回调请求不携带租户上下文
     * （Host 为平台统一回调域 auth.neihang.com）：
     * - GET：URL 有效性验证（echostr 验签解密）
     * - POST：事件推送（suite_ticket 每 10 分钟 / create_auth / cancel_auth）
     * 控制器内按 suite_id 手动解析服务商凭证，参照 Domain 模块
     * verify-file.php 的裸路由注册先例。
     */
    protected function registerSuiteCallbackRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $moduleDir = dirname((new \ReflectionClass($this))->getFileName());
        $path = $moduleDir . '/Routes/suite.php';

        if (file_exists($path)) {
            $this->loadRoutesFrom($path);
        }
    }
}
