<?php

namespace MultiTenantSaas\Modules\Ibot\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Services\Channels\TelegramChannel;
use MultiTenantSaas\Modules\Ibot\Services\IbotGateway;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * Telegram long polling 常驻命令（docs/ibot.md Phase 0）
 *
 * 跨租户轮询所有 active telegram ibot 的 getUpdates，逐条送入 IbotGateway。
 * offset 缓存持久化（重启不重复消费）。
 *
 * 约束：每个 Bot Token 同时只允许一个活跃轮询器——部署时经
 * supervisor/octane 单实例运行，勿多开。
 *
 * 用法：
 *   php artisan ibot:telegram-poll            # 常驻轮询全部
 *   php artisan ibot:telegram-poll --ibot=1   # 仅轮询指定 ibot
 *   php artisan ibot:telegram-poll --once     # 单轮后退出（测试/cron 用）
 */
class IbotTelegramPollCommand extends Command
{
    protected $signature = 'ibot:telegram-poll
                            {--ibot= : 仅轮询指定 ibot_id}
                            {--once : 单轮后退出}';

    protected $description = '轮询 Telegram Bot 更新（long polling）并送入 Ibot 网关';

    private const OFFSET_CACHE_PREFIX = 'ibot:tg:offset:';

    public function handle(TelegramChannel $telegram, IbotGateway $gateway): int
    {
        if (! config('ai.ibot.enabled', false)) {
            $this->warn('ibot 功能未启用（ai.ibot.enabled=false），退出。');

            return self::SUCCESS;
        }

        $timeout = (int) config('ai.ibot.telegram.poll_timeout', 30);

        do {
            $ibots = $this->loadTelegramIbots();

            if ($ibots->isEmpty()) {
                $this->line('无 active 的 telegram ibot，等待中…');
                sleep(10);

                continue;
            }

            foreach ($ibots as $ibot) {
                $this->pollOne($ibot, $telegram, $gateway, $timeout);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    /**
     * 跨租户加载 active telegram ibots（CLI 无租户上下文，须豁免作用域）
     */
    private function loadTelegramIbots()
    {
        return TenantScope::allowUnscoped(function () {
            $query = Ibot::where('channel_type', Ibot::CHANNEL_TELEGRAM)
                ->where('status', Ibot::STATUS_ACTIVE);

            if ($this->option('ibot')) {
                $query->where('ibot_id', (int) $this->option('ibot'));
            }

            return $query->get();
        });
    }

    private function pollOne(Ibot $ibot, TelegramChannel $telegram, IbotGateway $gateway, int $timeout): void
    {
        $offsetKey = self::OFFSET_CACHE_PREFIX . $ibot->ibot_id;
        $offset = (int) Cache::get($offsetKey, 0);

        $result = $telegram->getUpdates($ibot, $offset, $timeout);

        if (! $result['ok']) {
            return;
        }

        foreach ($result['updates'] as $update) {
            $updateId = (int) ($update['update_id'] ?? 0);

            // 先推进 offset，处理异常也不重复消费
            if ($updateId >= $offset) {
                $offset = $updateId + 1;
                Cache::forever($offsetKey, $offset);
            }

            $msg = $telegram->parseInbound($ibot, $update);

            if ($msg === null) {
                continue;
            }

            try {
                $gateway->handleInbound($ibot, $msg);
            } catch (\Throwable $e) {
                $this->error("[ibot:{$ibot->ibot_id}] 处理失败: {$e->getMessage()}");
                report($e);
            }
        }
    }
}
