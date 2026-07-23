<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use MultiTenantSaas\Events\TenantActivated;
use MultiTenantSaas\Events\TenantCreated;
use MultiTenantSaas\Modules\Auth\Models\Role;
use MultiTenantSaas\Modules\Billing\Models\SubscriptionPlan;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Operator\Models\Operator;

/**
 * 租户引导式注册服务（Operator 直连租户模式）
 *
 * 架构原则：
 * - Operator 即管理者：注册平台账户（Operator）后，申请创建租户，
 *   审核通过后通过 operator_tenants 关联直接成为该租户管理员
 * - 不再在 onboarding 流程中创建 users/tenant_users 记录
 *   （User 只在租户后台开启"开放注册"后才产生）
 * - 不再采集 admin_email/password（直接复用已登录 Operator 身份）
 *
 * 流程：
 * - Step1 基本信息：租户名称 + 域名配置（与 Operator 解耦）
 * - Step2 套餐选择
 * - Step3 支付信息（试用可跳过）
 * - Step4 完成：创建 Tenant（status=pending_approval）+ 触发 TenantCreated 事件
 *   不立即写入 operator_tenants；待平台审核通过（dispatch TenantActivated）时
 *   由 ListenTenantActivated 写入 operator_tenants 关联
 *
 * 会话存储：Cache key `onboarding.{token}`，TTL 1 小时
 */
class TenantOnboardingService
{
    /** 步骤：基础信息（租户名称、域名） */
    public const STEP_BASIC_INFO = 1;

    /** 步骤：域名配置（子域名 or 自定义域名）— 与 Step1 合并提交 */
    public const STEP_DOMAIN = 2;

    /** 步骤：套餐选择（含试用选项） */
    public const STEP_PLAN = 3;

    /** 步骤：支付信息（试用可跳过） */
    public const STEP_PAYMENT = 4;

    /** 步骤：完成（触发 Tenant 创建） */
    public const STEP_COMPLETE = 5;

    /** 需要提交数据的步骤 */
    public const DATA_STEPS = [self::STEP_BASIC_INFO, self::STEP_DOMAIN, self::STEP_PLAN, self::STEP_PAYMENT];

    /** 会话有效期（秒） */
    public const SESSION_TTL = 3600;

    /** Cache 会话 key 前缀 */
    public const SESSION_PREFIX = 'onboarding.';

    /** Operator 反向索引前缀（限制单 Operator 同时只有一个进行中会话） */
    public const OPERATOR_INDEX_PREFIX = 'onboarding:operator:';

    /**
     * 步骤名称映射
     */
    public function stepNames(): array
    {
        return [
            self::STEP_BASIC_INFO => trans('tenant.onboarding.step_names.1'),
            self::STEP_DOMAIN => trans('tenant.onboarding.step_names.2'),
            self::STEP_PLAN => trans('tenant.onboarding.step_names.3'),
            self::STEP_PAYMENT => trans('tenant.onboarding.step_names.4'),
            self::STEP_COMPLETE => trans('tenant.onboarding.step_names.5'),
        ];
    }

    /**
     * 各步骤字段校验规则
     *
     * 注意：Step1 不再采集 admin_email/password，只采集租户本身信息
     */
    protected function stepRules(int $step): array
    {
        return match ($step) {
            self::STEP_BASIC_INFO => [
                'name' => 'required|string|max:255',
                'industry' => 'nullable|string|max:100',
                'size' => 'nullable|string|in:small,medium,large',
                'contact_phone' => 'nullable|string|max:30',
            ],
            self::STEP_DOMAIN => [
                'domain_type' => 'nullable|in:subdomain,custom',
                'subdomain' => 'nullable|string|max:100',
                'domain' => 'nullable|string|max:255',
            ],
            self::STEP_PLAN => [
                'plan' => 'nullable|string|exists:subscription_plans,name',
                'plan_id' => 'nullable|integer|exists:subscription_plans,subscription_plan_id',
                'billing_cycle' => 'nullable|in:monthly,yearly',
                'trial' => 'nullable|boolean',
                'start_trial' => 'nullable|boolean',
            ],
            self::STEP_PAYMENT => [
                'payment_method' => 'nullable|string|max:50',
                'coupon_code' => 'nullable|string|max:64',
                'skip' => 'nullable|boolean',
            ],
            default => [],
        };
    }

    /**
     * 开始注册（Step 1）
     *
     * 由已认证的 Operator 发起，只采集租户本身信息，不再要 admin_email/password
     *
     * @param  array  $data  Step 1 数据（name、industry、size、contact_phone）
     * @param  int  $operatorId  发起者 Operator ID
     * @param  string|null  $clientIp  客户端 IP
     * @return string 会话 token
     *
     * @throws \InvalidArgumentException 字段校验失败
     * @throws \RuntimeException 同一 Operator 存在未完成会话
     */
    public function startRegistration(array $data, ?int $operatorId = null, ?string $clientIp = null): string
    {
        $this->validateData($data, $this->stepRules(self::STEP_BASIC_INFO));

        // 同一 Operator 同时只允许一个进行中的会话
        if ($operatorId && Cache::has(self::OPERATOR_INDEX_PREFIX . $operatorId)) {
            throw new \RuntimeException(trans('tenant.onboarding.registration_failed'));
        }

        $token = Str::random(64);
        $session = [
            'token' => $token,
            'operator_id' => $operatorId,
            'current_step' => self::STEP_DOMAIN,
            'completed_steps' => [self::STEP_BASIC_INFO],
            'data' => [self::STEP_BASIC_INFO => $data],
            'created_at' => now()->toIso8601String(),
            'completed' => false,
            'tenant_id' => null,
            'client_ip' => $clientIp,
        ];

        $this->saveSession($token, $session);
        Cache::put(self::OPERATOR_INDEX_PREFIX . $operatorId, $token, self::SESSION_TTL);

        return $token;
    }

    /**
     * 取发起该会话的 Operator ID
     */
    public function getOperatorId(string $token): ?int
    {
        $session = $this->loadSession($token);

        return $session['operator_id'] ?? null;
    }

    /**
     * 查询注册进度
     */
    public function getStatus(string $token, ?string $clientIp = null): ?array
    {
        $session = $this->loadSession($token);

        if (! $session) {
            return null;
        }

        if ($clientIp !== null && isset($session['client_ip']) && $session['client_ip'] !== $clientIp) {
            return null;
        }

        return $this->buildStatusResponse($session);
    }

    /**
     * 提交指定步骤数据
     *
     * @param  int  $step  步骤号
     * @param  array  $data  步骤数据
     * @param  string|null  $clientIp  客户端 IP
     * @return array 进度信息
     *
     * @throws \InvalidArgumentException token 无效、步骤非法或字段校验失败
     */
    public function saveStep(string $token, int $step, array $data, ?string $clientIp = null): array
    {
        $session = $this->loadSession($token);

        if (! $session) {
            throw new \InvalidArgumentException(trans('tenant.onboarding.invalid_token'));
        }

        if ($clientIp !== null && isset($session['client_ip']) && $session['client_ip'] !== $clientIp) {
            throw new \InvalidArgumentException(trans('tenant.onboarding.invalid_token'));
        }

        if ($session['completed']) {
            throw new \InvalidArgumentException(trans('tenant.onboarding.already_completed'));
        }

        if (! in_array($step, self::DATA_STEPS, true)) {
            throw new \InvalidArgumentException(trans('tenant.onboarding.invalid_step'));
        }

        if ($step !== $session['current_step']) {
            throw new \InvalidArgumentException(trans('tenant.onboarding.invalid_step'));
        }

        $this->validateData($data, $this->stepRules($step));

        $session['data'][$step] = $data;

        if (! in_array($step, $session['completed_steps'], true)) {
            $session['completed_steps'][] = $step;
        }

        $next = $step + 1;
        $session['current_step'] = $next <= self::STEP_PAYMENT ? $next : self::STEP_COMPLETE;

        $this->saveSession($token, $session);

        return $this->buildStatusResponse($session);
    }

    /**
     * 完成注册，触发 Tenant 创建（不立即写入 operator_tenants 关联）
     *
     * 流程：
     * - 在事务内创建 Tenant（status=pending_approval）、默认 Role、TenantSetting
     * - 触发 TenantCreated 事件（监听器可发邮件通知平台审核员）
     * - 待平台后台调用 approveTenant($tenantId) 时触发 TenantActivated 事件
     *   → ListenTenantActivated 监听器写入 operator_tenants 关联（role=tenant_admin, is_active=true, accepted_at=now()）
     *
     * @param  string|null  $clientIp  客户端 IP
     *
     * @throws \InvalidArgumentException token 无效或未完成全部步骤
     */
    public function complete(string $token, ?string $clientIp = null): Tenant
    {
        $session = $this->loadSession($token);

        if (! $session) {
            throw new \InvalidArgumentException(trans('tenant.onboarding.invalid_token'));
        }

        if ($clientIp !== null && isset($session['client_ip']) && $session['client_ip'] !== $clientIp) {
            throw new \InvalidArgumentException(trans('tenant.onboarding.invalid_token'));
        }

        if ($session['completed']) {
            throw new \InvalidArgumentException(trans('tenant.onboarding.already_completed'));
        }

        $missing = array_diff(self::DATA_STEPS, $session['completed_steps']);
        if (! empty($missing)) {
            throw new \InvalidArgumentException(trans('tenant.onboarding.incomplete_steps'));
        }

        $operatorId = $session['operator_id'];
        $basic = $session['data'][self::STEP_BASIC_INFO];
        $domain = $session['data'][self::STEP_DOMAIN];
        $plan = $session['data'][self::STEP_PLAN];

        $subscriptionPlan = $this->resolvePlan($plan);
        $startTrial = $this->resolveTrialFlag($plan);

        $tenant = DB::transaction(function () use ($basic, $domain, $subscriptionPlan, $operatorId) {
            $tenant = $this->createTenant($basic, $domain, $subscriptionPlan);

            // 为租户预置默认角色（待审核通过后供未来 User 注册使用）
            $this->createDefaultRole($tenant, 'tenant_admin', trans('tenant.onboarding.role_admin'));
            $this->createDefaultRole($tenant, 'end_user', trans('tenant.onboarding.role_user'));

            $this->provisionTenantSettings($tenant);

            // 为新租户预开通默认模块
            app(ModuleManager::class)->provisionTenantModules(
                $tenant->tenant_id,
                $subscriptionPlan->name
            );

            // 待平台审核通过后再激活；此处状态保持 pending_approval
            // 记录发起者 Operator ID，供审核通过时写入 operator_tenants 关联
            $tenant->onboarding_step = self::STEP_COMPLETE;
            $tenant->onboarding_completed = true;
            $tenant->status = 'pending_approval';
            $tenant->onboarding_operator_id = $operatorId;
            $tenant->save();

            return $tenant;
        });

        // 标记会话完成
        $session['completed'] = true;
        $session['current_step'] = self::STEP_COMPLETE;
        $session['tenant_id'] = $tenant->tenant_id;
        $this->saveSession($token, $session);

        // 释放 Operator 占用索引
        Cache::forget(self::OPERATOR_INDEX_PREFIX . $operatorId);

        // 触发 TenantCreated（不写 operator_tenants；由 TenantActivated 监听器处理）
        Event::dispatch(new TenantCreated($tenant));

        return $tenant;
    }

    /**
     * 平台审核通过：激活租户 + 触发 TenantActivated（供监听器写入 operator_tenants 关联）
     *
     * 由平台后台调用（如 PlatformAdminController::approve）
     *
     * @param  int  $approverOperatorId  审核人（平台 Operator）ID
     * @return Tenant 已激活的租户
     *
     * @throws \InvalidArgumentException 租户不存在或已激活
     */
    public function approveTenant(Tenant $tenant, int $approverOperatorId): Tenant
    {
        if ($tenant->status === 'active') {
            throw new \InvalidArgumentException('Tenant already active');
        }

        $tenant = DB::transaction(function () use ($tenant) {
            $tenant->status = 'active';
            $tenant->save();

            return $tenant;
        });

        // 监听器 ListenTenantActivated 会读 tenant.onboarding_operator_id
        // 并写入 operator_tenants 关联（role=tenant_admin, is_active=true, accepted_at=now）
        Event::dispatch(new TenantActivated($tenant));

        return $tenant;
    }

    /**
     * 创建租户记录
     */
    protected function createTenant(array $basic, array $domain, SubscriptionPlan $plan): Tenant
    {
        $subdomain = $domain['subdomain'] ?? $domain['domain'] ?? null;
        $customDomain = $domain['domain'] ?? null;
        $domainType = $domain['domain_type']
            ?? (! empty($customDomain) ? 'custom' : 'subdomain');

        $slug = $subdomain ?: $this->generateUniqueSlug($basic['name']);

        if ($domainType !== 'custom' || empty($customDomain)) {
            // 子域名模式：自动生成 {slug}.{wildcard_base} 作为 domain
            $wildcardBase = config('domain.wildcard_base');
            $customDomain = $wildcardBase ? "{$slug}.{$wildcardBase}" : null;
        }

        return Tenant::create([
            'name' => $basic['name'],
            'slug' => $slug,
            'domain' => $customDomain,
            'subscription_plan' => $plan->name,
            'subscription_plan_id' => $plan->subscription_plan_id,
            'subscription_started_at' => now(),
            'status' => 'pending_approval',
            'auto_renew' => false,
        ]);
    }

    /**
     * 创建租户级默认角色
     */
    protected function createDefaultRole(Tenant $tenant, string $name, string $displayName): Role
    {
        return Role::create([
            'tenant_id' => $tenant->tenant_id,
            'name' => $name,
            'display_name' => $displayName,
            'description' => $displayName,
            'is_system' => true,
        ]);
    }

    /**
     * 初始化租户默认配置
     */
    protected function provisionTenantSettings(Tenant $tenant): void
    {
        TenantSetting::set($tenant->tenant_id, 'info', 'name', $tenant->name);
        TenantSetting::set($tenant->tenant_id, 'auth', 'allow_phone_login', false);
        TenantSetting::set($tenant->tenant_id, 'auth', 'allow_password_login', true);
        TenantSetting::set($tenant->tenant_id, 'auth', 'email_domains', '');
        // 默认关闭开放注册（Operator 在 console 后台可改）
        TenantSetting::set($tenant->tenant_id, 'registration', 'allow_register', false);
        TenantSetting::set($tenant->tenant_id, 'registration', 'welcome_credits', 0);
    }

    /**
     * 解析套餐：优先按 plan_id，其次按 plan（name）
     */
    protected function resolvePlan(array $plan): SubscriptionPlan
    {
        if (! empty($plan['plan_id'])) {
            return SubscriptionPlan::where('subscription_plan_id', $plan['plan_id'])->firstOrFail();
        }

        if (! empty($plan['plan'])) {
            return SubscriptionPlan::where('name', $plan['plan'])->firstOrFail();
        }

        throw new \InvalidArgumentException(trans('tenant.onboarding.invalid_step'));
    }

    /**
     * 解析试用标志：兼容 trial / start_trial 两种字段
     */
    protected function resolveTrialFlag(array $plan): bool
    {
        if (array_key_exists('trial', $plan)) {
            return (bool) $plan['trial'];
        }

        if (array_key_exists('start_trial', $plan)) {
            return (bool) $plan['start_trial'];
        }

        return false;
    }

    /**
     * 根据租户名称生成唯一 slug
     */
    protected function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tenant';
        $slug = $base;

        for ($i = 0; $i < 5; $i++) {
            if (! Tenant::where('slug', $slug)->exists()) {
                return $slug;
            }
            $slug = $base . '-' . Str::lower(Str::random(6));
        }

        return $base . '-' . Str::lower(Str::random(10));
    }

    /**
     * 构建进度响应（无敏感字段）
     */
    protected function buildStatusResponse(array $session): array
    {
        return [
            'current_step' => $session['current_step'],
            'completed_steps' => $session['completed_steps'],
            'completed' => $session['completed'],
            'tenant_id' => $session['tenant_id'],
            'operator_id' => $session['operator_id'] ?? null,
            'data' => $session['data'],
            'step_names' => $this->stepNames(),
        ];
    }

    /**
     * 校验数据，失败时抛出 InvalidArgumentException（便于上层统一捕获为 422）
     */
    protected function validateData(array $data, array $rules): void
    {
        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new \InvalidArgumentException(
                $validator->errors()->first() ?: trans('tenant.onboarding.validation_failed')
            );
        }
    }

    /**
     * 持久化会话到 Cache
     */
    protected function saveSession(string $token, array $session): void
    {
        Cache::put(self::SESSION_PREFIX . $token, $session, self::SESSION_TTL);
    }

    /**
     * 从 Cache 读取会话
     */
    protected function loadSession(string $token): ?array
    {
        $session = Cache::get(self::SESSION_PREFIX . $token);

        return is_array($session) ? $session : null;
    }
}
