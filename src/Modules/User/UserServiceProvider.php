<?php

namespace MultiTenantSaas\Modules\User;

use MultiTenantSaas\Contracts\ToolRegistryContract;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\User\Services\Tools\UserCreateHandler;
use MultiTenantSaas\Modules\User\Services\Tools\UserGetDevicesHandler;
use MultiTenantSaas\Modules\User\Services\Tools\UserGetLoginLogsHandler;
use MultiTenantSaas\Modules\User\Services\Tools\UserGetProfileHandler;
use MultiTenantSaas\Modules\User\Services\Tools\UserGetStatsHandler;
use MultiTenantSaas\Modules\User\Services\Tools\UserGetTenantsHandler;
use MultiTenantSaas\Modules\User\Services\Tools\UserListHandler;
use MultiTenantSaas\Modules\User\Services\Tools\UserSearchHandler;
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
        $this->registerTools();
        //
    }

    private function registerTools(): void
    {
        $registry = app(ToolRegistryContract::class);

        $registry->register('user_list', 'User List', 'List', UserListHandler::class, ['type' => 'object', 'properties' => ['status' => ['type' => 'string', 'description' => '状态过滤'], 'per_page' => ['type' => 'integer', 'description' => '每页数量']]], 'user', 'L1');
        $registry->register('user_search', 'User Search', 'Search', UserSearchHandler::class, ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => '搜索关键词']], 'required' => ['query']], 'user', 'L1');
        $registry->register('user_get_profile', 'User Get Profile', 'Get profile', UserGetProfileHandler::class, ['type' => 'object', 'properties' => ['user_id' => ['type' => 'integer', 'description' => '用户ID']], 'required' => ['user_id']], 'user', 'L1');
        $registry->register('user_create', 'User Create', 'Create', UserCreateHandler::class, ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'description' => '姓名'], 'email' => ['type' => 'string', 'description' => '邮箱'], 'phone' => ['type' => 'string', 'description' => '手机号']], 'required' => ['name']], 'user', 'L2');
        $registry->register('user_update', 'User Update', 'Update', UserUpdateHandler::class, ['type' => 'object', 'properties' => ['user_id' => ['type' => 'integer', 'description' => '用户ID'], 'name' => ['type' => 'string', 'description' => '姓名'], 'status' => ['type' => 'string', 'description' => '状态']], 'required' => ['user_id']], 'user', 'L2');
        $registry->register('user_toggle_status', 'User Toggle Status', 'Toggle status', UserToggleStatusHandler::class, ['type' => 'object', 'properties' => ['user_id' => ['type' => 'integer', 'description' => '用户ID']], 'required' => ['user_id']], 'user', 'L2');
        $registry->register('user_get_login_logs', 'User Get Login Logs', 'Get login logs', UserGetLoginLogsHandler::class, ['type' => 'object', 'properties' => ['user_id' => ['type' => 'integer', 'description' => '用户ID']], 'required' => ['user_id']], 'user', 'L1');
        $registry->register('user_get_devices', 'User Get Devices', 'Get devices', UserGetDevicesHandler::class, ['type' => 'object', 'properties' => ['user_id' => ['type' => 'integer', 'description' => '用户ID']], 'required' => ['user_id']], 'user', 'L1');
        $registry->register('user_get_stats', 'User Get Stats', 'Get stats', UserGetStatsHandler::class, ['type' => 'object', 'properties' => ['user_id' => ['type' => 'integer', 'description' => '用户ID']], 'required' => ['user_id']], 'user', 'L1');
        $registry->register('user_get_tenants', 'User Get Tenants', 'Get tenants', UserGetTenantsHandler::class, ['type' => 'object', 'properties' => ['user_id' => ['type' => 'integer', 'description' => '用户ID']], 'required' => ['user_id']], 'user', 'L1');
    }
}
