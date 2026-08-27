<?php

namespace MultiTenantSaas\Modules\WechatWork\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\WechatWork\Models\ServiceProvider;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkSuiteService;

/**
 * create_auth 授权入库 Job
 *
 * 企微协议要求回调在 1000ms 内响应，故 SuiteCallbackController 收到
 * create_auth 后仅记录 auth_code/state 并立即派发本 Job，由 queue worker
 * 异步完成 get_permanent_code 换码 → 一次性 state 校验 → 幂等入库。
 *
 * tries=1：auth_code 一次性且 10 分钟有效，重试无意义（失败时企微侧
 * 应用已授权，可删应用重扫或人工处理）。
 */
class ProcessCreateAuthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public string $authCode,
        public string $state,
        public int $tenantId,
        public int $serviceProviderId,
    ) {}

    public function handle(WechatWorkSuiteService $suite): void
    {
        TenantContext::setTenantId((string) $this->tenantId);

        $provider = ServiceProvider::find($this->serviceProviderId);

        if ($provider === null) {
            Log::warning('[WechatWorkSuite] create_auth Job 服务商不存在', [
                'service_provider_id' => $this->serviceProviderId,
            ]);

            return;
        }

        try {
            $result = $suite->exchangePermanentCode($provider, $this->authCode);

            // 一次性校验并消费 state，防重放
            $context = $suite->verifyAuthorizationState($this->state, $this->tenantId);

            $suite->saveAuthorization($this->tenantId, (int) $provider->service_provider_id, [
                'corp_id' => $result['corp_id'],
                'agent_id' => $result['agent_id'],
                'permanent_code' => $result['permanent_code'],
            ]);

            Log::info('[WechatWorkSuite] 企业授权完成并入库', [
                'tenant_id' => $this->tenantId,
                'corp_id' => $result['corp_id'],
                'corp_name' => $result['corp_name'],
                'agent_id' => $result['agent_id'],
                'origin_domain' => $context['origin_domain'] ?? '',
            ]);
        } catch (\Throwable $e) {
            Log::warning('[WechatWorkSuite] create_auth 换取 permanent_code 未消费', [
                'service_provider_id' => $this->serviceProviderId,
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
