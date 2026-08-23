<?php

namespace MultiTenantSaas\Modules\Auth\Services\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * OAuth State 管理（无 Session，纯 Token/Cache 方式）
 *
 * 适用于 API-only 架构：
 * - redirect 阶段：生成随机 state，存入 Cache（TTL 10 分钟），嵌入授权 URL
 * - callback 阶段：从请求参数取 state，校验 Cache 中存在后删除（一次性使用）
 *
 * 不依赖 Session/Cookie，SPA 前端无需额外处理。
 */
trait ManagesOAuthState
{
    /**
     * State 有效期（秒）
     */
    protected int $stateTtl = 600;

    /**
     * 生成 state 并存入 Cache
     *
     * state 格式: {tenantId}.{random} —— 租户 ID 明文前缀，供统一回调域
     * （OAUTH_CALLBACK_DOMAIN）下回调请求 Host 为平台统一域、无法解析租户
     * 域名时直接恢复租户上下文。前缀可被篡改但无妨：verifyState 仍按该租户
     * ID 校验 Cache，不匹配即拒绝，不构成安全风险。
     *
     * @param  int  $tenantId  租户 ID（绑定到特定租户，防跨租户重放）
     * @param  string  $provider  提供商标识（如 wechat_work / alipay）
     * @param  array  $context  上下文（origin_domain 等，回调时取回）
     * @return string state 值
     */
    protected function generateState(int $tenantId, string $provider, array $context = []): string
    {
        $state = $tenantId . '.' . Str::random(24);
        $key = $this->stateCacheKey($state, $tenantId, $provider);

        Cache::put($key, $context ?: true, $this->stateTtl);

        return $state;
    }

    /**
     * 从 state 解析租户 ID（统一回调域恢复租户）
     *
     * 旧格式（纯随机 40 字符，无租户前缀）返回 null。
     */
    protected function tenantIdFromState(string $state): ?int
    {
        if (preg_match('/^(\d{4,20})\./', $state, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * 校验 state（一次性：验证后立即删除），返回上下文
     *
     * 旧格式 state（Cache 值为 true）→ 返回空数组。
     *
     * @throws HttpException state 无效时 abort(403)
     */
    protected function verifyState(string $state, int $tenantId, string $provider): array
    {
        if ($state === '') {
            abort(403, trans('common.oauth_state_invalid'));
        }

        $key = $this->stateCacheKey($state, $tenantId, $provider);

        if (! Cache::has($key)) {
            abort(403, trans('common.oauth_state_invalid'));
        }

        $context = Cache::get($key);

        // 一次性使用，防重放
        Cache::forget($key);

        return is_array($context) ? $context : [];
    }

    /**
     * 构造 Cache key
     *
     * 格式: oauth_state:{provider}:{tenantId}:{state_hash}
     * 使用 hash 避免 key 过长
     */
    protected function stateCacheKey(string $state, int $tenantId, string $provider): string
    {
        return sprintf('oauth_state:%s:%d:%s', $provider, $tenantId, hash('sha256', $state));
    }
}
