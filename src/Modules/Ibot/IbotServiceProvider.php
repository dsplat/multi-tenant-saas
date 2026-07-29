<?php

namespace MultiTenantSaas\Modules\Ibot;

use Illuminate\Support\Facades\Notification;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Ibot\Console\IbotTelegramPollCommand;
use MultiTenantSaas\Modules\Ibot\Notifications\IbotNotificationChannel;
use MultiTenantSaas\Modules\Ibot\Services\IbotBindingService;
use MultiTenantSaas\Modules\Ibot\Services\IbotChannelResolver;
use MultiTenantSaas\Modules\Ibot\Services\IbotGateway;
use MultiTenantSaas\Modules\Ibot\Services\IbotNotifier;

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
    }

    protected function registerModuleCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                IbotTelegramPollCommand::class,
            ]);
        }
    }
}
