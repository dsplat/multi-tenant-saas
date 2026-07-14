<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use Illuminate\Support\Facades\Cache;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use MultiTenantSaas\Events\TenantCreated;
use MultiTenantSaas\Modules\Auth\Models\Role;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Billing\Models\SubscriptionPlan;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantUser;
use MultiTenantSaas\Modules\Monitoring\Services\TrialService;

/**
 * 租户引导式注册服务
 *
 * 职责：
 * - 5 步注册流程的状态管理与数据校验
 * - 断点续填：基于 Cache 存储会话，用户中断后可从上次步骤恢复
 * - 自动初始化：注册完成后创建 Tenant/Role/User/TenantSetting，按需启动试用
 *
 * 会话存储：Cache key `onboarding.{token}`，TTL 1 小时
 *
 * 字段兼容：步骤 2/3/4 兼容多种字段命名（subdomain/domain、plan/plan_id、trial/start_trial），
 * 以适配不同调用方（Controller $stepFields 或直接调用）。
 */
class TenantOnboardingService
{
    /** 步骤：基础信息（租户名称、管理员邮箱、密码） */
    public const STEP_BASIC_INFO = 1;

    /** 步骤：域名配置（子域名 or 自定义域名） */
    public const STEP_DOMAIN = 2;

    /** 步骤：套餐选择（含试用选项） */
    public const STEP_PLAN = 3;

    /** 步骤：支付信息（试用可跳过） */
    public const STEP_PAYMENT = 4;

    /** 步骤：完成（触发自动初始化） */
    public const STEP_COMPLETE = 5;

    /** 需要提交数据的步骤 */
    public const DATA_STEPS = [self::STEP_BASIC_INFO, self::STEP_DOMAIN, self::STEP_PLAN, self::STEP_PAYMENT];

    /** 会话有效期（秒） */
    public const SESSION_TTL = 3600;

    /** Cache 会话 key 前缀 */
    public const SESSION_PREFIX = 'onboarding.';

    /** 邮箱占用反向索引前缀（用于重复注册检测） */
    public const EMAIL_INDEX_PREFIX = 'onboarding:email:';

    /**
     * 步骤名称映射
     */
    public static function stepNames(): array
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
     * 各步骤字段校验规则（兼容多种字段命名）
     */
    protected function stepRules(int $step): array
    {
        return match ($step) {
            self::STEP_BASIC_INFO => [
                'name' => 'required|string|max:255',
                'admin_email' => 'required|email',
                'password' => 'required|min:8|regex:/[a-z]/|regex:/[A-Z]/|regex:/[0-9]/',
            ],
            self::STEP_DOMAIN => [
                'domain_type' => 'nullable|in:subdomain,custom',
                'subdomain' => 'nullable|string|max:100',
                'domain' => 'nullable|string|max:100',
                'custom_domain' => 'nullable|string|max:255',
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
     * 校验基础信息并创建会话，返回 session token 供后续步骤使用。
     *
     * @param  array  $data  Step 1 数据（name、admin_email、password）
     * @param  string|null  $clientIp  客户端 IP，用于 token 绑定
     * @return string 会话 token
     *
     * @throws \InvalidArgumentException 字段校验失败
     * @throws \RuntimeException 邮箱重复或会话冲突
     */
    public function startRegistration(array $data, ?string $clientIp = null): string
    {
        $this->validateData($data, $this->stepRules(self::STEP_BASIC_INFO));

        $email = $data['admin_email'];

        // 已存在同名邮箱的用户，禁止重复注册
        if (User::where('email', $email)->exists()) {
            throw new \RuntimeException(trans('tenant.onboarding.registration_failed'));
        }

        // 存在未完成的注册会话，禁止重复发起
        if (Cache::has(self::EMAIL_INDEX_PREFIX . $email)) {
            throw new \RuntimeException(trans('tenant.onboarding.registration_failed'));
        }

        $token = Str::random(64);
        $sessionData = $data;
        $sessionData['password'] = Hash::make($data['password']);
        $session = [
            'token' => $token,
            'current_step' => self::STEP_DOMAIN,
            'completed_steps' => [self::STEP_BASIC_INFO],
            'data' => [self::STEP_BASIC_INFO => $sessionData],
            'created_at' => now()->toIso8601String(),
            'completed' => false,
            'tenant_id' => null,
            'client_ip' => $clientIp,
        ];

        $this->saveSession($token, $session);
        Cache::put(self::EMAIL_INDEX_PREFIX . $email, $token, self::SESSION_TTL);

        return $token;
    }

    /**
     * 查询注册进度
     *
     * @param  string  $token  会话 token
     * @param  string|null  $clientIp  客户端 IP，用于绑定验证
     * @return array{current_step:int,completed_steps:array,completed:bool,data:array,step_names:array}|null
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
     * 校验步骤合法性（不可跳步）与字段，保存数据并推进当前步骤。
     *
     * @param  int  $step  步骤号（1-4）
     * @param  string|null  $clientIp  客户端 IP，用于绑定验证
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

        // 严格顺序：仅允许提交当前待办步骤，禁止跳步
        if ($step !== $session['current_step']) {
            throw new \InvalidArgumentException(trans('tenant.onboarding.invalid_step'));
        }

        $this->validateData($data, $this->stepRules($step));

        $session['data'][$step] = $data;

        if (! in_array($step, $session['completed_steps'], true)) {
            $session['completed_steps'][] = $step;
        }

        // 推进到下一步
        $next = $step + 1;
        $session['current_step'] = $next <= self::STEP_PAYMENT ? $next : self::STEP_COMPLETE;

        $this->saveSession($token, $session);

        return $this->buildStatusResponse($session);
    }

    /**
     * 完成注册，触发自动初始化
     *
     * 初始化流程：
     * - 创建 Tenant 记录
     * - 创建默认角色（admin / user）
     * - 创建默认管理员 User + TenantUser 关联
     * - 初始化 TenantSetting 默认配置
     * - 如选试用，调用 TrialService::startTrial()
     * - 触发 TenantCreated 事件
     *
     * @param  string|null  $clientIp  客户端 IP，用于绑定验证
     *
     * @throws \InvalidArgumentException token 无效、未完成全部步骤或已完成
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

        $data = $session['data'];
        $basic = $data[self::STEP_BASIC_INFO];
        $domain = $data[self::STEP_DOMAIN];
        $plan = $data[self::STEP_PLAN];

        $subscriptionPlan = $this->resolvePlan($plan);
        $startTrial = $this->resolveTrialFlag($plan);

        $tenant = DB::transaction(function () use ($basic, $domain, $subscriptionPlan, $startTrial) {
            $tenant = $this->createTenant($basic, $domain, $subscriptionPlan);

            $adminRole = $this->createDefaultRole($tenant, 'tenant_admin', trans('tenant.onboarding.role_admin'));
            $this->createDefaultRole($tenant, 'end_user', trans('tenant.onboarding.role_user'));

            $adminUser = $this->createAdminUser($basic);
            $this->attachAdminMember($tenant, $adminUser, $adminRole);

            $this->provisionTenantSettings($tenant);

            // 为新租户开通默认模块
            app(ModuleManager::class)->provisionTenantModules(
                $tenant->tenant_id,
                $subscriptionPlan->name
            );

            if ($startTrial) {
                TrialService::startTrial($tenant->tenant_id, $subscriptionPlan->subscription_plan_id);
                $tenant = $tenant->fresh();
            }

            $tenant->onboarding_step = self::STEP_COMPLETE;
            $tenant->onboarding_completed = true;
            $tenant->save();

            return $tenant;
        });

        // 标记会话已完成
        $session['completed'] = true;
        $session['current_step'] = self::STEP_COMPLETE;
        $session['tenant_id'] = $tenant->tenant_id;
        $this->saveSession($token, $session);

        // 释放邮箱占用索引（后续重复注册由 User 唯一约束保障）
        Cache::forget(self::EMAIL_INDEX_PREFIX . $basic['admin_email']);

        // 触发事件（MailTemplateService 尚未就绪，统一由事件监听器处理后续通知）
        Event::dispatch(new TenantCreated($tenant));

        return $tenant;
    }

    /**
     * 创建租户记录
     */
    protected function createTenant(array $basic, array $domain, SubscriptionPlan $plan): Tenant
    {
        $subdomain = $domain['subdomain'] ?? $domain['domain'] ?? null;
        $customDomain = $domain['custom_domain'] ?? null;
        $domainType = $domain['domain_type']
            ?? (! empty($customDomain) ? 'custom' : 'subdomain');

        $slug = $subdomain ?: $this->generateUniqueSlug($basic['name']);

        if ($domainType !== 'custom' || empty($customDomain)) {
            $customDomain = null;
        }

        return Tenant::create([
            'name' => $basic['name'],
            'slug' => $slug,
            'custom_domain' => $customDomain,
            'subscription_plan' => $plan->name,
            'subscription_plan_id' => $plan->subscription_plan_id,
            'subscription_started_at' => now(),
            'status' => 'active',
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
     * 创建默认管理员用户
     */
    protected function createAdminUser(array $basic): User
    {
        return User::create([
            'name' => $basic['name'],
            'email' => $basic['admin_email'],
            'password' => $basic['password'],
            'role' => 'platform_user',
        ]);
    }

    /**
     * 关联管理员到租户
     */
    protected function attachAdminMember(Tenant $tenant, User $user, Role $adminRole): TenantUser
    {
        return TenantUser::create([
            'tenant_id' => $tenant->tenant_id,
            'user_id' => $user->user_id,
            'role' => 'tenant_admin',
            'role_id' => $adminRole->role_id,
            'is_active' => true,
            'joined_at' => now(),
        ]);
    }

    /**
     * 初始化租户默认配置（复用 TenantController::provisionTenant 模式）
     */
    protected function provisionTenantSettings(Tenant $tenant): void
    {
        TenantSetting::set($tenant->tenant_id, 'info', 'name', $tenant->name);
        TenantSetting::set($tenant->tenant_id, 'auth', 'allow_phone_login', false);
        TenantSetting::set($tenant->tenant_id, 'auth', 'allow_password_login', true);
        TenantSetting::set($tenant->tenant_id, 'auth', 'email_domains', '');
        TenantSetting::set($tenant->tenant_id, 'registration', 'allow_register', true);
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
     * 构建进度响应（屏蔽密码等敏感字段）
     */
    protected function buildStatusResponse(array $session): array
    {
        $safeData = $session['data'];

        if (isset($safeData[self::STEP_BASIC_INFO]['password'])) {
            unset($safeData[self::STEP_BASIC_INFO]['password']);
        }

        return [
            'current_step' => $session['current_step'],
            'completed_steps' => $session['completed_steps'],
            'completed' => $session['completed'],
            'tenant_id' => $session['tenant_id'],
            'data' => $safeData,
            'step_names' => self::stepNames(),
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
