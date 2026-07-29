<?php

namespace MultiTenantSaas\Modules\Ibot;

use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\Ibot\Console\IbotTelegramPollCommand;
use MultiTenantSaas\Modules\Ibot\Services\IbotBindingService;
use MultiTenantSaas\Modules\Ibot\Services\IbotChannelResolver;
use MultiTenantSaas\Modules\Ibot\Services\IbotGateway;

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
