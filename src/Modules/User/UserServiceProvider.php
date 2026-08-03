<?php

namespace MultiTenantSaas\Modules\User;

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Contracts\ToolRegistryContract;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\User\Services\Tools\UserCreateHandler;
use MultiTenantSaas\Modules\User\Services\Tools\UserGetStatsHandler;
use MultiTenantSaas\Modules\User\Services\Tools\UserGetTenantsHandler;
use MultiTenantSaas\Modules\User\Services\Tools\UserListHandler;
use MultiTenantSaas\Modules\User\Services\Tools\UserToggleStatusHandler;
use MultiTenantSaas\Modules\User\Services\Tools\UserUpdateHandler;

class UserServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'user';

    protected function registerModuleBindings(): void
    {
        //
    }

    protected function bootModule(): void
    {
        $this->loadTenantApiRoutes();
        $this->registerTools();
    }

    /**
     * 以 api/v1 前缀注册租户后台路由（tenant.php）
     *
     * 基类 loadModuleRoutes() 对 tenant.php 不加前缀，而生产 nginx 仅转发
     * /api/* 到 PHP，console 前端实际调用 /api/v1/tenant/members|settings，
     * 故仿照 Auth/SSL/ApiToken 模块范式补带前缀注册。
     */
    protected function loadTenantApiRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $tenantRoute = $this->getModulePath('Routes/tenant.php');
        if ($tenantRoute && file_exists($tenantRoute)) {
            Route::middleware(['auth:sanctum', 'throttle:api', 'tenant.identify'])
                ->prefix('api/v1')
                ->group($tenantRoute);
        }
    }

    private function registerTools(): void
    {
        $registry = app(ToolRegistryContract::class);

        $registry->register('user_list', 'User List', 'List', UserListHandler::class, ['type' => 'object', 'properties' => ['status' => ['type' => 'string', 'description' => '状态过滤'], 'per_page' => ['type' => 'integer', 'description' => '每页数量']]], 'user', 'L1');
        $registry->register('user_create', 'User Create', 'Create', UserCreateHandler::class, ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'description' => '姓名'], 'email' => ['type' => 'string', 'description' => '邮箱'], 'phone' => ['type' => 'string', 'description' => '手机号']], 'required' => ['name']], 'user', 'L2');
        $registry->register('user_update', 'User Update', 'Update', UserUpdateHandler::class, ['type' => 'object', 'properties' => ['user_id' => ['type' => 'integer', 'description' => '用户ID'], 'name' => ['type' => 'string', 'description' => '姓名'], 'status' => ['type' => 'string', 'description' => '状态']], 'required' => ['user_id']], 'user', 'L2');
        $registry->register('user_toggle_status', 'User Toggle Status', 'Toggle status', UserToggleStatusHandler::class, ['type' => 'object', 'properties' => ['user_id' => ['type' => 'integer', 'description' => '用户ID']], 'required' => ['user_id']], 'user', 'L2');
        $registry->register('user_get_stats', 'User Get Stats', 'Get stats', UserGetStatsHandler::class, ['type' => 'object', 'properties' => ['user_id' => ['type' => 'integer', 'description' => '用户ID']], 'required' => ['user_id']], 'user', 'L1');
        $registry->register('user_get_tenants', 'User Get Tenants', 'Get tenants', UserGetTenantsHandler::class, ['type' => 'object', 'properties' => ['user_id' => ['type' => 'integer', 'description' => '用户ID']], 'required' => ['user_id']], 'user', 'L1');
    }
}
