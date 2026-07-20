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
     * @param  int  $tenantId  租户 ID（绑定到特定租户，防跨租户重放）
     * @param  string  $provider  提供商标识（如 wechat_work / alipay）
     * @return string 随机 state 值
     */
    protected function generateState(int $tenantId, string $provider): string
    {
        $state = Str::random(40);
        $key = $this->stateCacheKey($state, $tenantId, $provider);

        Cache::put($key, true, $this->stateTtl);

        return $state;
    }

    /**
     * 校验 state（一次性：验证后立即删除）
     *
     * @throws HttpException state 无效时 abort(403)
     */
    protected function verifyState(string $state, int $tenantId, string $provider): void
    {
        if ($state === '') {
            abort(403, trans('common.oauth_state_invalid'));
        }

        $key = $this->stateCacheKey($state, $tenantId, $provider);

        if (! Cache::has($key)) {
            abort(403, trans('common.oauth_state_invalid'));
        }

        // 一次性使用，防重放
        Cache::forget($key);
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
