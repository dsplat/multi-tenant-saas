<?php

namespace MultiTenantSaas\Modules\Ibot\Services;

use InvalidArgumentException;
use MultiTenantSaas\Modules\Ibot\Contracts\IbotChannelContract;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Services\Channels\TelegramChannel;
use MultiTenantSaas\Modules\Ibot\Services\Channels\WechatWorkChannel;

/**
 * 频道解析器 — channel_type → IbotChannelContract 实现
 *
 * 后续频道（飞书/钉钉/微信 iLink）在 $map 中追加，
 * 下游可经 config('ai.ibot.extra_channels') 注入扩展实现。
 */
class IbotChannelResolver
{
    /** @var array<string, class-string<IbotChannelContract>> */
    private array $map = [
        Ibot::CHANNEL_TELEGRAM => TelegramChannel::class,
        Ibot::CHANNEL_WECHAT_WORK => WechatWorkChannel::class,
    ];

    public function resolve(Ibot $ibot): IbotChannelContract
    {
        $map = array_merge($this->map, config('ai.ibot.extra_channels', []));

        $class = $map[$ibot->channel_type] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("[Ibot] 不支持的频道类型: {$ibot->channel_type}");
        }

        return app($class);
    }

    public function supports(string $channelType): bool
    {
        return isset(array_merge($this->map, config('ai.ibot.extra_channels', []))[$channelType]);
    }
}
