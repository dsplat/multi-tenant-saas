<?php

namespace MultiTenantSaas\Modules\Ibot\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Models\OperatorIbotBinding;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkSuiteService;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 绑定码流程（docs/ibot.md 第四节）
 *
 * 控制台生成一次性绑定码（短 TTL）→ operator 扫码进 bot 会话，
 * 首条消息携带绑定码 → consume() 校验并写绑定。
 *
 * 企微扫码即绑（2026-08）：二维码内容 = 网页授权链接（snsapi_base），
 * 扫码 → 授权回调换 userid（putPending 暂存）→ 确认页 POST → consume 建立绑定。
 *
 * 绑定码一次性消费；同 operator 同 ibot 重复绑定 = 更新 external_id（换设备/重扫）；
 * 同 external_id 已被其他 operator 占用 = 拒绝（一个 IM 会话只归一人）。
 */
class IbotBindingService
{
    private const CACHE_PREFIX = 'ibot:bind:';

    // 扫码确认暂存（回调换取 userid 后、用户点确认前；短时效，随绑定码过期）
    private const PENDING_PREFIX = 'ibot:bind:pending:';

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
     *
     * @param  string  $externalName  IM 平台成员展示名（企微姓名），空则回退 external_id
     */
    public function consume(string $code, Ibot $ibot, string $externalId, string $externalName = ''): ?OperatorIbotBinding
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

        // external_id 已被其他 operator 占用（仅 active 互斥，解绑 revoked 后可重新绑定）→ 拒绝
        // 公开回调/队列无 TenantContext，显式绕过租户全局作用域查全量
        $occupied = OperatorIbotBinding::withoutGlobalScope(TenantScope::class)
            ->where('ibot_id', $ibot->ibot_id)
            ->where('external_id', $externalId)
            ->where('status', OperatorIbotBinding::STATUS_ACTIVE)
            ->where('operator_id', '!=', $payload['operator_id'])
            ->exists();

        if ($occupied) {
            return null;
        }

        // 一次性消费
        Cache::forget($key);

        // 同 operator 同 ibot：更新 external_id 并激活（换设备/重扫/解绑后重绑场景）
        $binding = OperatorIbotBinding::withoutGlobalScope(TenantScope::class)
            ->where('operator_id', $payload['operator_id'])
            ->where('ibot_id', $ibot->ibot_id)
            ->first();

        if ($binding) {
            $binding->update([
                'external_id' => $externalId,
                'external_name' => $externalName !== '' ? $externalName : $externalId,
                'status' => OperatorIbotBinding::STATUS_ACTIVE,
            ]);

            return $binding->refresh();
        }

        // 同 ibot 同 external_id 的历史绑定（已解绑）→ 转交当前 operator 激活
        //（避免唯一索引 ibot_bindings_ibot_external_unique 冲突，保证「解绑后可换人重绑」）
        $revoked = OperatorIbotBinding::withoutGlobalScope(TenantScope::class)
            ->where('ibot_id', $ibot->ibot_id)
            ->where('external_id', $externalId)
            ->where('status', OperatorIbotBinding::STATUS_REVOKED)
            ->first();

        if ($revoked) {
            $revoked->update([
                'operator_id' => $payload['operator_id'],
                'external_name' => $externalName !== '' ? $externalName : $externalId,
                'status' => OperatorIbotBinding::STATUS_ACTIVE,
            ]);

            return $revoked->refresh();
        }

        return OperatorIbotBinding::create([
            'tenant_id' => $payload['tenant_id'],
            'operator_id' => $payload['operator_id'],
            'ibot_id' => $ibot->ibot_id,
            'external_id' => $externalId,
            'external_name' => $externalName !== '' ? $externalName : $externalId,
            'is_default_channel' => false,
            'status' => OperatorIbotBinding::STATUS_ACTIVE,
        ]);
    }

    /**
     * 该 IM 账号（external_id）是否已绑定当前机器人（active，供扫码时提示「已绑定」）
     */
    public function isBound(Ibot $ibot, string $externalId): bool
    {
        // 公开回调无 TenantContext，显式绕过租户全局作用域（与 consume 同策略）
        return OperatorIbotBinding::withoutGlobalScope(TenantScope::class)
            ->where('ibot_id', $ibot->ibot_id)
            ->where('external_id', $externalId)
            ->where('status', OperatorIbotBinding::STATUS_ACTIVE)
            ->exists();
    }

    /**
     * 仅校验绑定码存在且匹配当前 bot（不消费，扫码确认页展示前用）
     */
    public function peekBindCode(string $code, Ibot $ibot): bool
    {
        $payload = Cache::get(self::CACHE_PREFIX . Str::upper(trim($code)));

        return is_array($payload)
            && (int) $payload['ibot_id'] === (int) $ibot->ibot_id
            && (int) $payload['tenant_id'] === (int) $ibot->tenant_id;
    }

    /**
     * 暂存扫码回调换取的企微身份（确认页阶段持有，POST 确认时取走）
     */
    public function putPending(int $ibotId, string $code, string $externalId): void
    {
        Cache::put(
            self::PENDING_PREFIX . "{$ibotId}:" . Str::upper(trim($code)),
            $externalId,
            (int) config('ai.ibot.bind_code_ttl', 600)
        );
    }

    /**
     * 取走扫码确认身份（一次性，取走后即失效）
     */
    public function takePending(int $ibotId, string $code): ?string
    {
        $key = self::PENDING_PREFIX . "{$ibotId}:" . Str::upper(trim($code));
        $externalId = Cache::get($key);

        if (is_string($externalId) && $externalId !== '') {
            Cache::forget($key);

            return $externalId;
        }

        return null;
    }

    /**
     * 构造企微扫码绑定授权链接（网页授权 snsapi_base）
     *
     * 扫码后企微内置浏览器打开 → 静默换取 code → 跳转绑定回调（state=ibot_id:code）。
     * corp_id 取 ibot 凭证，缺失时回退租户套件授权记录。
     * 回调域按接入模式区分：代开发（suite）只能用平台统一回调域
     * （可信域名由服务商代管）；自建（self）才可用租户自定义域名。
     */
    public function buildWechatWorkBindUrl(Ibot $ibot, string $code): string
    {
        $tenantId = (int) $ibot->tenant_id;
    
        $auths = app(WechatWorkSuiteService::class)->appAuthorizations($tenantId);
        $suiteMode = $auths !== [];
    
        $corpId = (string) ($ibot->credentials['corp_id'] ?? '');
        if ($corpId === '') {
            if (! $suiteMode) {
                return '';
            }
            $corpId = (string) ($auths[0]->corp_id ?? '');
        }
    
        if ($corpId === '') {
            return '';
        }
    
        $redirect = $this->resolveBindCallbackUrl($tenantId, $suiteMode);
        if ($redirect === '') {
            return '';
        }

        return 'https://open.weixin.qq.com/connect/oauth2/authorize?' . http_build_query([
            'appid' => $corpId,
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => 'snsapi_base',
            'state' => "{$ibot->ibot_id}:{$code}",
        ]) . '#wechat_redirect';
    }

    /**
     * 绑定回调 URL：按接入模式区分可信域名（与 WechatWorkOAuthService::getConfig 同规则）
     *
     * - suite（代开发）：可信域名由服务商代管，只能用平台统一回调域
     *   （auth.oauth.callback_domain，如 auth.neihang.com）；租户自定义域名
     *   （如 club.lanyantu.com）仅自建模式可用，代开发模式填了必报 redirect_uri 错
     * - self（自建）：租户自定义域名优先（需在企微「网页授权及JS-SDK」
     *   可信域名内），平台统一回调域兑底
     */
    private function resolveBindCallbackUrl(int $tenantId, bool $suiteMode): string
    {
        $callbackDomain = config('auth.oauth.callback_domain', '');

        if ($suiteMode) {
            return $callbackDomain !== ''
                ? "https://{$callbackDomain}/api/v1/ibot/bind/wechat-work/callback"
                : '';
        }

        $domain = Tenant::where('tenant_id', $tenantId)->value('domain');
        if ($domain) {
            return "https://{$domain}/api/v1/ibot/bind/wechat-work/callback";
        }

        if ($callbackDomain !== '') {
            return "https://{$callbackDomain}/api/v1/ibot/bind/wechat-work/callback";
        }

        return '';
    }
}
