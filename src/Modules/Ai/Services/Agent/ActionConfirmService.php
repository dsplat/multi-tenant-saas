<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Agent;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * L2 操作确认令牌服务（AI 代操作的人类确认点）
 *
 * 确认凭证在服务端闭环，LLM 无法伪造或跳过：
 *  - 签发：AgentRuntime 遇 L2 工具时签发一次性 confirm_token
 *          （工具 slug + 参数哈希 + 会话 ID + 租户 ID，cache 存储，TTL 5 分钟）
 *  - 消费：用户点确认后由 confirm-action 端点消费，先删后校验杜绝并发重放；
 *          校验租户/会话归属 + 参数哈希（确认的就是看到的）
 *  - 取消：同样走 consume 使 token 作废
 */
class ActionConfirmService
{
    /** 令牌有效期（秒） */
    public const TTL_SECONDS = 300;

    private const CACHE_PREFIX = 'ai:action_confirm:';

    /**
     * 签发一次性确认令牌
     *
     * @param  int  $tenantId  租户 ID
     * @param  int  $conversationId  会话 ID
     * @param  string  $toolSlug  L2 工具标识
     * @param  array  $arguments  LLM 生成的工具参数（原样存服务端，执行时不信任前端回传）
     * @param  string|null  $toolCallId  OpenAI tool_call id（续答时保持上下文配对）
     * @param  int|null  $ttlSeconds  自定义有效期（秒），null 用默认 TTL_SECONDS；IM 文本确认场景放宽用
     * @return array{token: string, args_hash: string, expires_in: int}
     */
    public function issue(int $tenantId, int $conversationId, string $toolSlug, array $arguments, ?string $toolCallId = null, ?int $ttlSeconds = null): array
    {
        $ttl = $ttlSeconds !== null && $ttlSeconds > 0 ? $ttlSeconds : self::TTL_SECONDS;
        $token = Str::random(48);
        $argsHash = $this->hashArguments($arguments);

        Cache::put(self::CACHE_PREFIX . $token, [
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'tool_slug' => $toolSlug,
            'arguments' => $arguments,
            'args_hash' => $argsHash,
            'tool_call_id' => $toolCallId,
            'issued_at' => now()->timestamp,
        ], $ttl);

        return [
            'token' => $token,
            'args_hash' => $argsHash,
            'expires_in' => $ttl,
        ];
    }

    /**
     * 一次性消费确认令牌（确认与取消共用，消费即作废）
     *
     * @param  string  $token  确认令牌
     * @param  int  $tenantId  当前请求租户 ID
     * @param  int  $conversationId  当前请求会话 ID
     * @param  string  $argsHash  前端回传的参数哈希（须与签发时一致）
     * @return array 签发时存储的载荷（tool_slug/arguments/tool_call_id 等）
     *
     * @throws \RuntimeException 令牌不存在/过期/归属不符/哈希不符
     */
    public function consume(string $token, int $tenantId, int $conversationId, string $argsHash): array
    {
        $key = self::CACHE_PREFIX . $token;
        $payload = Cache::get($key);

        if (! is_array($payload)) {
            throw new \RuntimeException('确认凭证不存在或已过期，请重新发起操作');
        }

        // 一次性消费：校验前即删除，杜绝并发重放
        Cache::forget($key);

        if ((int) ($payload['tenant_id'] ?? 0) !== $tenantId
            || (int) ($payload['conversation_id'] ?? 0) !== $conversationId) {
            throw new \RuntimeException('确认凭证与当前会话不匹配');
        }

        if (! hash_equals((string) ($payload['args_hash'] ?? ''), $argsHash)) {
            throw new \RuntimeException('操作参数与确认时不一致，已拒绝执行');
        }

        return $payload;
    }

    /**
     * 参数规范化哈希（递归键排序，同参数不同键序哈希一致）
     */
    public function hashArguments(array $arguments): string
    {
        return hash('sha256', (string) json_encode(
            $this->canonicalize($arguments),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * 递归按键排序（列表数组保持原序）
     */
    private function canonicalize(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->canonicalize($value);
            }
        }

        return $data;
    }
}
