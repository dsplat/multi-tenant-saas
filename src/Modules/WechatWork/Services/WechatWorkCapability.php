<?php

namespace MultiTenantSaas\Modules\WechatWork\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Modules\Billing\Models\SubscriptionPlan;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;

/**
 * 企微能力门控服务（阶段 C，docs/wecom-service-provider-plan.md 11.2）
 *
 * 租户企微能力按套餐 features 分层门控（复用 SubscriptionPlan::hasFeature）：
 * - base      基础包：登录 + 应用消息 + ibot + 内部群（free 起含）
 * - intercom  互通包：客户/客户群/群发/欢迎语/客服（推荐 basic 起含）
 * - self      自建模式：出口 IP 独享 + 完整权限（推荐 pro/enterprise）
 * - archive   会话存档增值：仅自建可用（10.6 能力边界，feature 依赖 self）
 *
 * 门控语义（商业化渐进式，docs/wecom-service-provider-plan.md 11.2）：
 * - Billing 拆包缺失 / 租户从未订阅（无套餐记录）→ 全放行（存量租户零影响，
 *   商业化正式收紧时将「从未订阅」回退改为 free base-only 即可）
 * - 显式订阅了套餐 → 严格按套餐 features 分层门控，能力不足明确报错。
 *
 * 配额（limits.wechat_work_license_basic/intercom、wechat_work_proxy_ips）与
 * 实际用量（tenant_settings wechatwork.usage）一并在此暴露，供 admin 台账与
 * console 自服务展示（11.4/11.5）。
 */
class WechatWorkCapability
{
    public const BASE = 'wechat_work_base';

    public const INTERCOM = 'wechat_work_intercom';

    public const SELF = 'wechat_work_self';

    public const ARCHIVE = 'wechat_work_archive';

    /**
     * 代开发许可免费窗口天数（11.3：授权起 90 天内许可零成本）
     */
    protected const FREE_TRIAL_DAYS = 90;

    /**
     * 能力别名 → features 键
     */
    protected const CAPABILITIES = [
        'base' => self::BASE,
        'intercom' => self::INTERCOM,
        'self' => self::SELF,
        'archive' => self::ARCHIVE,
    ];

    /**
     * 租户是否已具备指定企微能力包
     */
    public function has(int $tenantId, string $capability): bool
    {
        $feature = self::CAPABILITIES[$capability] ?? null;

        if ($feature === null) {
            return false;
        }

        // Billing 拆包未安装/未迁移（无套餐体系）：不做能力门控（全放行）
        if (! class_exists(SubscriptionPlan::class) || ! Schema::hasTable('subscription_plans')) {
            return true;
        }

        $plan = $this->resolvePlan($tenantId);

        // 租户从未订阅（无套餐记录，存量租户/未接入商业化）→ 不做能力门控全放行；
        // 显式订阅了套餐才按 features 严格分层（商业化渐进式收紧策略）
        if ($plan === null) {
            return true;
        }

        if (! $plan->hasFeature($feature)) {
            return false;
        }

        // 会话存档仅自建模式可用（10.6 能力边界：archive 依赖 self）
        return $capability !== 'archive' || $plan->hasFeature(self::SELF);
    }

    /**
     * 能力不足时抛出明确错误（feature_not_enabled 风格，不静默）
     *
     * @throws DomainException 租户不具备指定能力包
     */
    public function assert(int $tenantId, string $capability, ?string $message = null): void
    {
        if (! $this->has($tenantId, $capability)) {
            throw new DomainException(
                $message ?? trans('wechat_work.capability_not_enabled', ['capability' => $capability, 'tenant' => $tenantId])
            );
        }
    }

    /**
     * 当前套餐能力包清单（base/intercom/self/archive 含/不含，供两端 UI 展示）
     *
     * @return array<string, bool>
     */
    public function featureList(int $tenantId): array
    {
        $result = [];
        foreach (array_keys(self::CAPABILITIES) as $alias) {
            $result[$alias] = $this->has($tenantId, $alias);
        }

        return $result;
    }

    /**
     * 许可配额与已用量台账（11.4 admin / 11.5 console）
     *
     * - limits: 套餐配额（wechat_work_license_basic / _intercom / wechat_work_proxy_ips）
     * - usage: tenant_settings wechatwork.usage（license_basic_used / license_intercom_used / proxy_ip）
     *
     * @return array{limits: array<string, mixed>, usage: array<string, mixed>}
     */
    public function licenseOverview(int $tenantId): array
    {
        $plan = $this->resolvePlan($tenantId);

        // 从未订阅（null）→ 配额不限（null，与 enterprise「不限」同语义）；显式套餐保留其配额
        $limits = [
            'wechat_work_license_basic' => $plan?->getLimit('wechat_work_license_basic'),
            'wechat_work_license_intercom' => $plan?->getLimit('wechat_work_license_intercom'),
            'wechat_work_proxy_ips' => $plan?->getLimit('wechat_work_proxy_ips'),
        ];

        $usage = TenantSetting::get($tenantId, 'wechatwork', 'usage', []);

        return [
            'limits' => $limits,
            'usage' => is_array($usage) ? $usage : [],
        ];
    }

    /**
     * 代开发许可 90 天免费窗口截止时间（授权记录 authorized_at + 90 天，11.3）
     *
     * 非代开发模式或无授权记录时返回 null（无免费窗口）。
     */
    public function freeTrialEndsAt(int $tenantId): ?Carbon
    {
        if (! class_exists(WechatWorkSuiteService::class) || ! Schema::hasTable('wechat_work_authorizations')) {
            return null;
        }

        $authorization = app(WechatWorkSuiteService::class)->authorization($tenantId);

        if ($authorization === null || ! $authorization->isAuthorized() || $authorization->authorized_at === null) {
            return null;
        }

        return $authorization->authorized_at->copy()->addDays(self::FREE_TRIAL_DAYS);
    }

    /**
     * 解析租户当前订阅计划（从未订阅返回 null → 调用方按「未接入商业化」全放行）
     */
    protected function resolvePlan(int $tenantId): ?SubscriptionPlan
    {
        if (! class_exists(SubscriptionPlan::class) || ! Schema::hasTable('subscription_plans')) {
            return null;
        }

        $tenant = Tenant::find($tenantId);

        if ($tenant === null) {
            return null;
        }

        $plan = null;

        if ($tenant->subscription_plan_id) {
            $plan = SubscriptionPlan::find($tenant->subscription_plan_id);
        }

        // subscription_plan 字段 DEFAULT 'free'（历史遗留，不代表显式订阅）——
        // 仅当有 plan_id，或名称非默认值（如平台租户 enterprise）时才按名解析。
        // 否则视为未订阅 → null → 全放行（存量租户零影响，2026-08-28 回归教训）
        if ($plan === null && $tenant->subscription_plan
            && ! ($tenant->subscription_plan === 'free' && ! $tenant->subscription_plan_id)) {
            $plan = SubscriptionPlan::where('name', $tenant->subscription_plan)->first();
        }

        return $plan;
    }
}