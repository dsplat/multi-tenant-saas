<?php

namespace MultiTenantSaas\Modules\Ibot\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Models\OperatorIbotBinding;

/**
 * 绑定码流程（docs/ibot.md 第四节）
 *
 * 控制台生成一次性绑定码（短 TTL）→ operator 扫码进 bot 会话，
 * 首条消息携带绑定码 → consume() 校验并写绑定。
 *
 * 绑定码一次性消费；同 operator 同 ibot 重复绑定 = 更新 external_id（换设备/重扫）；
 * 同 external_id 已被其他 operator 占用 = 拒绝（一个 IM 会话只归一人）。
 */
class IbotBindingService
{
    private const CACHE_PREFIX = 'ibot:bind:';

    /**
     * 为 operator 生成绑定码（缓存存储，TTL 默认 10 分钟）
     */
    public function generateBindCode(int $operatorId, Ibot $ibot): string
    {
        $code = Str::upper(Str::random(8));

        Cache::put(self::CACHE_PREFIX . $code, [
            'tenant_id' => (int) $ibot->tenant_id,
            'operator_id' => $operatorId,
            'ibot_id' => (int) $ibot->ibot_id,
        ], config('ai.ibot.bind_code_ttl', 600));

        return $code;
    }

    /**
     * 消费绑定码，建立/更新绑定（失败返回 null）
     */
    public function consume(string $code, Ibot $ibot, string $externalId): ?OperatorIbotBinding
    {
        $key = self::CACHE_PREFIX . Str::upper(trim($code));
        $payload = Cache::get($key);

        if (! is_array($payload)) {
            return null;
        }

        // 绑定码必须与当前 bot、当前租户匹配（防跨 bot/跨租户重放）
        if ((int) $payload['ibot_id'] !== (int) $ibot->ibot_id
            || (int) $payload['tenant_id'] !== (int) $ibot->tenant_id) {
            return null;
        }

        // external_id 已被其他 operator 占用 → 拒绝
        $occupied = OperatorIbotBinding::where('ibot_id', $ibot->ibot_id)
            ->where('external_id', $externalId)
            ->where('operator_id', '!=', $payload['operator_id'])
            ->exists();

        if ($occupied) {
            return null;
        }

        // 一次性消费
        Cache::forget($key);

        // 同 operator 同 ibot：更新 external_id 并激活（换设备/重扫场景）
        $binding = OperatorIbotBinding::where('operator_id', $payload['operator_id'])
            ->where('ibot_id', $ibot->ibot_id)
            ->first();

        if ($binding) {
            $binding->update([
                'external_id' => $externalId,
                'status' => OperatorIbotBinding::STATUS_ACTIVE,
            ]);

            return $binding->refresh();
        }

        return OperatorIbotBinding::create([
            'tenant_id' => $payload['tenant_id'],
            'operator_id' => $payload['operator_id'],
            'ibot_id' => $ibot->ibot_id,
            'external_id' => $externalId,
            'is_default_channel' => false,
            'status' => OperatorIbotBinding::STATUS_ACTIVE,
        ]);
    }
}
