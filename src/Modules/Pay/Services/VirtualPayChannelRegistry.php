<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Pay\Services;

use MultiTenantSaas\Modules\Pay\Contracts\VirtualPayChannelContract;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 虚拟支付渠道注册表
 *
 * 项目层在 Provider boot 中注册实现：
 *   app(VirtualPayChannelRegistry::class)->register(new PointsVirtualPayChannel(...));
 */
class VirtualPayChannelRegistry
{
    /** @var array<string, VirtualPayChannelContract> */
    protected array $channels = [];

    public function register(VirtualPayChannelContract $channel): void
    {
        $this->channels[$channel->name()] = $channel;
    }

    public function has(string $name): bool
    {
        return isset($this->channels[$name]);
    }

    public function get(string $name): VirtualPayChannelContract
    {
        if (! $this->has($name)) {
            throw new UnprocessableEntityHttpException("Virtual pay channel [{$name}] is not enabled");
        }

        return $this->channels[$name];
    }

    /** @return array<string, VirtualPayChannelContract> */
    public function all(): array
    {
        return $this->channels;
    }
}
