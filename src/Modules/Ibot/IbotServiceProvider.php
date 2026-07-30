<?php

namespace MultiTenantSaas\Modules\Ibot;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Ibot\Console\IbotTelegramPollCommand;
use MultiTenantSaas\Modules\Ibot\Notifications\IbotNotificationChannel;
use MultiTenantSaas\Modules\Ibot\Services\IbotBindingService;
use MultiTenantSaas\Modules\Ibot\Services\IbotChannelResolver;
use MultiTenantSaas\Modules\Ibot\Services\IbotGateway;
use MultiTenantSaas\Modules\Ibot\Services\IbotNotifier;
use MultiTenantSaas\Modules\Ibot\Services\Tools\GenerateIbotBindCodeTool;
use MultiTenantSaas\Modules\Ibot\Services\Tools\IbotSetupStatusTool;
use MultiTenantSaas\Modules\Ibot\Services\Tools\SaveIbotConfigTool;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\VerifyOperatorTenant;

/**
 * Ibot 模块 — IM 机器人随身 AI 小助理（docs/ibot.md）
 *
 * operator 扫码绑定 IM 机器人（P0: Telegram long polling），
 * 入向消息经 IbotGateway → Job → AgentRuntime（系统小秘书或指定 agent）。
 * 平台级开关 ai.ibot.enabled（默认关闭，AI 可选性铁律）。
 */
class IbotServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'ibot';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(IbotChannelResolver::class);
        $this->app->singleton(IbotBindingService::class);
        $this->app->singleton(IbotGateway::class);
        $this->app->singleton(IbotNotifier::class);
    }

    protected function bootModule(): void
    {
        // Laravel Notification 的 ibot 通道驱动（via() 返回 'ibot' 时生效）
        Notification::extend('ibot', fn ($app) => $app->make(IbotNotificationChannel::class));

        $this->registerAssistantTools();
    }

    /**
     * 小助手引导工具（category=secretary，范式同 Knowledge/Ai 模块）
     *
     * 引导链路：navigate 带路到配置页 → ibot_setup_status 报缺口 →
     * suggest_form_fill 预填 → save_ibot_config 确认保存 → generate_ibot_bind_code 出码。
     */
    private function registerAssistantTools(): void
    {
        // Ai 模块可选（AI 可选性铁律），未绑定时静默跳过
        if (! $this->app->bound(ToolRegistryContract::class)) {
            return;
        }

        $registry = $this->app->make(ToolRegistryContract::class);

        $registry->register(
            'ibot_setup_status',
            'Ibot Setup Status',
            'Check IM bot (WeChat Work / Telegram) channel setup status: configured fields, missing fields, webhook URL and next-step advice. Call this FIRST when the user asks how to connect an IM bot; then guide with navigate → suggest_form_fill → save_ibot_config → generate_ibot_bind_code',
            IbotSetupStatusTool::class,
            ['type' => 'object', 'properties' => []],
            'secretary',
        );

        $registry->register(
            'save_ibot_config',
            'Save Ibot Config',
            'Create or update IM bot channel credentials for current tenant (whitelisted fields per channel; masked values keep existing secrets). Requires user confirmation. wechat_work fields: corp_id/corp_secret/agent_id/token/encoding_aes_key; telegram fields: bot_token/bot_username',
            SaveIbotConfigTool::class,
            ['type' => 'object', 'properties' => [
                'channel_type' => ['type' => 'string', 'description' => '频道类型：wechat_work 或 telegram'],
                'credentials' => ['type' => 'object', 'description' => '凭证字段映射（仅白名单字段生效，掩码值不覆盖既有）'],
                'name' => ['type' => 'string', 'description' => '机器人名称（可选）'],
            ], 'required' => ['channel_type', 'credentials']],
            'secretary',
            'L2',
        );

        $registry->register(
            'generate_ibot_bind_code',
            'Generate Ibot Bind Code',
            'Generate a one-time bind code for the current operator to bind an active IM bot; the user sends the code to the bot in IM to finish binding. Requires user confirmation',
            GenerateIbotBindCodeTool::class,
            ['type' => 'object', 'properties' => [
                'channel_type' => ['type' => 'string', 'description' => '频道类型（可选；多个激活机器人时必传）'],
            ]],
            'secretary',
            'L2',
        );
    }

    protected function registerModuleCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                IbotTelegramPollCommand::class,
            ]);
        }
    }

    /**
     * 覆写基类路由加载：tenant.php 需挂到 api/v1 前缀（基类默认无前缀），
     * 并带 tenant.identify 保证 TenantContext 正确解析（范式同 Knowledge 模块）。
     * api.php / public.php 照基类约定自行加载（不能调 parent，否则 tenant.php 会被重复注册）。
     */
    protected function loadModuleRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $apiRoute = $this->getModulePath('Routes/api.php');
        if ($apiRoute && file_exists($apiRoute)) {
            Route::middleware(['auth:sanctum', 'throttle:api', 'tenant.identify', VerifyOperatorTenant::class])
                ->prefix('api/v1')
                ->group($apiRoute);
        }

        $publicRoute = $this->getModulePath('Routes/public.php');
        if ($publicRoute && file_exists($publicRoute)) {
            Route::middleware(['api'])
                ->prefix('api/v1')
                ->group($publicRoute);
        }

        $tenantRoute = $this->getModulePath('Routes/tenant.php');
        if ($tenantRoute && file_exists($tenantRoute)) {
            Route::middleware(['auth:sanctum', 'throttle:api', 'tenant.identify'])
                ->prefix('api/v1')
                ->group($tenantRoute);
        }
    }
}
