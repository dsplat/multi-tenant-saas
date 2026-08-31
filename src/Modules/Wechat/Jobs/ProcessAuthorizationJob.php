<?php

namespace MultiTenantSaas\Modules\Wechat\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Wechat\Models\Authorization;
use MultiTenantSaas\Modules\Wechat\Models\ComponentProvider;
use MultiTenantSaas\Modules\Wechat\Services\WechatComponentService;

/**
 * 授权回跳换码入库 Job
 *
 * 微信授权页完成后浏览器重定向到平台域 authorize-callback（带 auth_code，
 * 600 秒有效），控制器仅记录并立即派发本 Job，由 queue worker 异步完成
 * api_query_auth 换 authorizer_refresh_token → 一次性 state 校验 → 幂等入库。
 *
 * tries=1：auth_code 一次性且 600 秒有效，重试无意义（失败时微信侧
 * 账号已授权，可重新发起授权或人工处理）。
 */
class ProcessAuthorizationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public string $authCode,
        public string $state,
        public int $tenantId,
        public int $componentProviderId,
    ) {}

    public function handle(WechatComponentService $component): void
    {
        TenantContext::setTenantId((string) $this->tenantId);

        $provider = ComponentProvider::find($this->componentProviderId);

        if ($provider === null) {
            Log::warning('[WechatComponent] 授权 Job 组件不存在', [
                'component_provider_id' => $this->componentProviderId,
            ]);

            return;
        }

        try {
            $result = $component->exchangeAuthorization($provider, $this->authCode);

            // 一次性校验并消费 state，防重放
            $context = $component->verifyAuthorizationState($this->state, $this->tenantId);

            $component->saveAuthorization($this->tenantId, (int) $provider->component_provider_id, [
                'authorizer_appid' => $result['authorizer_appid'],
                'authorizer_type' => $this->resolveAuthorizerType($result['auth_type']),
                'authorizer_refresh_token' => $result['authorizer_refresh_token'],
                'nickname' => $result['nickname'],
                'head_img' => $result['head_img'],
            ]);

            Log::info('[WechatComponent] 账号授权完成并入库', [
                'tenant_id' => $this->tenantId,
                'authorizer_appid' => $result['authorizer_appid'],
                'nickname' => $result['nickname'],
                'origin_domain' => $context['origin_domain'] ?? '',
            ]);
        } catch (\Throwable $e) {
            Log::warning('[WechatComponent] 授权换取 authorizer_refresh_token 未消费', [
                'component_provider_id' => $this->componentProviderId,
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 微信 auth_type → 本地账号类型映射
     *
     * 1=公众号 2=小程序；未识别（如 3 授权页多选场景单次仅返回一个账号）
     * 时默认公众号。
     */
    protected function resolveAuthorizerType(string $authType): string
    {
        return $authType === '2'
            ? Authorization::TYPE_MINI_PROGRAM
            : Authorization::TYPE_OFFICIAL_ACCOUNT;
    }
}
