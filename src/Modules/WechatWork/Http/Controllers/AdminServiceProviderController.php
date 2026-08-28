<?php

namespace MultiTenantSaas\Modules\WechatWork\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\WechatWork\Models\ServiceProvider;
use MultiTenantSaas\Modules\WechatWork\Models\WechatWorkAuthorization;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkCapability;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkSuiteService;

/**
 * 平台管理后台 - 企微服务商配置控制器
 *
 * 管理服务商凭证（service_providers 系统级记录）、连接测试、已授权租户列表。
 * 权限：沿用 setting.view / setting.update（与系统设置一致，同 AdminAiController）。
 */
class AdminServiceProviderController extends Controller
{
    /** suite_secret / encoding_aes_key 掩码（列表返回/回存跳过，同 SystemSetting 安全模式） */
    private const SECRET_MASK = '********';

    public function __construct(
        private readonly WechatWorkSuiteService $suite,
    ) {}

    // ==================================================================
    // 服务商凭证 CRUD
    // ==================================================================

    public function providerIndex(): JsonResponse
    {
        $providers = ServiceProvider::query()
            ->whereNull('tenant_id')
            ->orderBy('service_provider_id')
            ->get()
            ->map(fn (ServiceProvider $p) => $this->presentProvider($p))
            ->values();

        return response()->json(['success' => true, 'data' => $providers]);
    }

    public function providerStore(Request $request): JsonResponse
    {
        $validated = $this->validateProvider($request);

        $provider = new ServiceProvider($validated);
        $provider->tenant_id = null; // 系统级配置
        $provider->save();

        return response()->json(['success' => true, 'data' => $this->presentProvider($provider)], 201);
    }

    public function providerUpdate(Request $request, int $providerId): JsonResponse
    {
        $provider = ServiceProvider::query()->whereNull('tenant_id')->find($providerId);

        if ($provider === null) {
            return response()->json(['success' => false, 'message' => trans('common.not_found')], 404);
        }

        $validated = $this->validateProvider($request, $providerId);

        // 掩码/空值 = 未修改，跳过回存避免覆盖真实密钥（app_encoding_aes_key 同属加密存储）
        foreach (['suite_secret', 'encoding_aes_key', 'app_encoding_aes_key'] as $field) {
            $value = $validated[$field] ?? null;
            if (! is_string($value) || $value === '' || $value === self::SECRET_MASK) {
                unset($validated[$field]);
            }
        }

        $provider->fill($validated);
        $provider->save();

        return response()->json(['success' => true, 'data' => $this->presentProvider($provider)]);
    }

    public function providerDestroy(int $providerId): JsonResponse
    {
        $provider = ServiceProvider::query()->whereNull('tenant_id')->find($providerId);

        if ($provider === null) {
            return response()->json(['success' => false, 'message' => trans('common.not_found')], 404);
        }

        $authorizationCount = WechatWorkAuthorization::query()
            ->where('service_provider_id', $providerId)
            ->where('status', WechatWorkAuthorization::STATUS_AUTHORIZED)
            ->count();

        if ($authorizationCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "仍有 {$authorizationCount} 个租户持有该服务商的代开发授权，请先在租户侧解除授权后删除",
            ], 409);
        }

        $provider->delete();

        return response()->json(['success' => true, 'message' => trans('common.deleted')]);
    }

    // ==================================================================
    // 连接测试 / 授权租户列表
    // ==================================================================

    public function providerTest(int $providerId): JsonResponse
    {
        $provider = ServiceProvider::query()->whereNull('tenant_id')->find($providerId);

        if ($provider === null) {
            return response()->json(['success' => false, 'message' => trans('common.not_found')], 404);
        }

        try {
            $result = $this->suite->testSuiteToken($provider);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => '连接失败：' . $e->getMessage(),
                'data' => ['suite_id' => $provider->suite_id],
            ], 502);
        }

        return response()->json(['success' => true, 'data' => [
            'suite_id' => $provider->suite_id,
            'access_token_prefix' => $result['access_token'],
            'expires_in' => $result['expires_in'],
        ]]);
    }

    public function authorizations(): JsonResponse
    {
        $suite = $this->suite;

        // 模板级应用回调凭证是否已配置（自动带出场景下企业级无需逐租户回填）
        $provider = $suite->provider();
        $templateAppConfigured = $provider !== null
            && (string) ($provider->app_callback_token ?? '') !== ''
            && (string) ($provider->app_encoding_aes_key ?? '') !== '';

        $rows = WechatWorkAuthorization::query()
            ->leftJoin('tenants', 'tenants.tenant_id', '=', 'wechat_work_authorizations.tenant_id')
            ->orderByDesc('wechat_work_authorizations.updated_at')
            ->get([
                'wechat_work_authorizations.authorization_id',
                'wechat_work_authorizations.tenant_id',
                'wechat_work_authorizations.corp_id',
                'wechat_work_authorizations.agent_id',
                'wechat_work_authorizations.app_callback_token',
                'wechat_work_authorizations.status',
                'wechat_work_authorizations.authorized_at',
                'wechat_work_authorizations.revoked_at',
                'tenants.name as tenant_name',
                'tenants.domain as tenant_domain',
            ])
            ->map(function ($row) use ($suite, $templateAppConfigured) {
                // 应用回调 URL 为模板统一地址（自动带出，所有企业一致）；凭证是否已配置
                // （企业级或模板级任一）只留布尔，密文/明文均不出库
                $row->app_callback_url = $suite->appCallbackUrlUnified();
                $row->app_callback_url_legacy = $suite->appCallbackUrl((int) $row->tenant_id);
                $row->app_callback_configured = $templateAppConfigured
                    || ($row->app_callback_token !== null && $row->app_callback_token !== '');
                unset($row->app_callback_token);

                return $row;
            })
            ->values();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * 保存租户应用回调凭证（企业级覆盖；模板级凭证已配置时此字段可留空回退模板级）
     */
    public function appCallbackUpdate(Request $request, int $authorizationId): JsonResponse
    {
        $authorization = WechatWorkAuthorization::query()->find($authorizationId);

        if ($authorization === null) {
            return response()->json(['success' => false, 'message' => trans('common.not_found')], 404);
        }

        $validated = $request->validate([
            'app_callback_token' => 'nullable|string|max:255',
            'app_encoding_aes_key' => 'nullable|string|max:255',
            'app_callback_url' => 'nullable|string|max:500|url',
        ]);

        // 留空 = 清空企业级覆盖，回退模板级统一凭证（自动带出场景下模板级即事实标准）
        $authorization->app_callback_token = $validated['app_callback_token'] ?? null;
        $authorization->app_encoding_aes_key = $validated['app_encoding_aes_key'] ?? null;
        $authorization->app_callback_url = $validated['app_callback_url']
            ?? $this->suite->appCallbackUrl((int) $authorization->tenant_id);
        $authorization->save();

        Log::info('[WechatWorkSuite] 应用回调凭证已回填', [
            'tenant_id' => $authorization->tenant_id,
            'corp_id' => $authorization->corp_id,
        ]);

        return response()->json(['success' => true, 'message' => '应用回调配置已保存']);
    }

    // ==================================================================
    // 租户企微出口代理（9.1）
    // ==================================================================

    /**
     * 租户企微接入能力总览（阶段 C，11.4 admin「企微接入」区块）
     *
     * 返回能力包状态（features）、许可配额/已用量台账（limits/usage）、
     * 接入模式（suite/self/none）与代开发许可 90 天免费窗口截止。
     */
    public function capabilityShow(int $tenantId): JsonResponse
    {
        if (! Tenant::query()->where('tenant_id', $tenantId)->exists()) {
            return response()->json(['success' => false, 'message' => trans('common.not_found')], 404);
        }

        $capability = app(WechatWorkCapability::class);
        $overview = $capability->licenseOverview($tenantId);

        $tenant = Tenant::find($tenantId);
        $authorization = $this->suite->authorization($tenantId);

        if ($authorization !== null && $authorization->isAuthorized()) {
            $mode = 'suite';
        } else {
            $corpId = TenantSetting::get($tenantId, 'wechatwork', 'corp_id', '');
            $mode = ! empty($corpId) ? 'self' : 'none';
        }

        $freeTrialEndsAt = $capability->freeTrialEndsAt($tenantId);

        return response()->json(['success' => true, 'data' => [
            'plan' => $tenant?->subscription_plan_id ? ($tenant?->subscription_plan ?: null) : null,
            'features' => $capability->featureList($tenantId),
            'limits' => $overview['limits'],
            'usage' => $overview['usage'],
            'mode' => $mode,
            'authorized' => $mode === 'suite',
            'free_trial_ends_at' => $freeTrialEndsAt?->toDateTimeString(),
        ]]);
    }

    /**
     * 读取租户企微出口代理配置（password 永不出库，仅回传 has_password）
     *
     * 企微 API 要求服务器出口 IP 在企业应用可信 IP 白名单内（否则 60020），
     * 平台为租户分配独立代理出口（IP 刚性，不可共享摊薄），客户将代理出口 IP
     * 加入企业后台可信 IP 后，企业侧接口全部经该代理出网。
     */
    public function proxyShow(int $tenantId): JsonResponse
    {
        if (! Tenant::query()->where('tenant_id', $tenantId)->exists()) {
            return response()->json(['success' => false, 'message' => trans('common.not_found')], 404);
        }

        $config = TenantSetting::get($tenantId, 'wechatwork', 'proxy', []);

        return response()->json(['success' => true, 'data' => [
            'enabled' => ! empty($config['enabled']),
            'scheme' => $config['scheme'] ?? 'http',
            'host' => (string) ($config['host'] ?? ''),
            'port' => (string) ($config['port'] ?? ''),
            'username' => (string) ($config['username'] ?? ''),
            'has_password' => ! empty($config['password']),
            // 出口 IP 为代理服务器公网 IP（客户需加入企业可信 IP），配置时由运营人工核对
            'exit_ip' => (string) ($config['exit_ip'] ?? ''),
        ]]);
    }

    /**
     * 保存租户企微出口代理配置（密码掩码跳过回存，JSON 整体加密存储）
     */
    public function proxyUpdate(Request $request, int $tenantId): JsonResponse
    {
        if (! Tenant::query()->where('tenant_id', $tenantId)->exists()) {
            return response()->json(['success' => false, 'message' => trans('common.not_found')], 404);
        }

        $validated = $request->validate([
            'enabled' => 'required|boolean',
            'scheme' => 'nullable|in:http,https,socks5',
            'host' => 'required_with:enabled|nullable|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'exit_ip' => 'nullable|string|max:64',
        ]);

        $current = TenantSetting::get($tenantId, 'wechatwork', 'proxy', []);
        $config = is_array($current) ? $current : [];

        $config['enabled'] = ! empty($validated['enabled']);
        if (array_key_exists('scheme', $validated) && $validated['scheme'] !== null) {
            $config['scheme'] = $validated['scheme'];
        }
        if (array_key_exists('host', $validated)) {
            $config['host'] = (string) $validated['host'];
        }
        if (array_key_exists('port', $validated) && $validated['port'] !== null) {
            $config['port'] = (int) $validated['port'];
        }
        if (array_key_exists('username', $validated)) {
            $config['username'] = (string) $validated['username'];
        }
        // 掩码占位符 = 未修改，跳过回存避免覆盖真实密码
        if (! empty($validated['password']) && $validated['password'] !== self::SECRET_MASK) {
            $config['password'] = (string) $validated['password'];
        }
        if (array_key_exists('exit_ip', $validated)) {
            $config['exit_ip'] = (string) $validated['exit_ip'];
        }

        // 整组加密存储（password 随 JSON 一并加密）
        TenantSetting::set($tenantId, 'wechatwork', 'proxy', $config, true, '企微出口代理（企业侧接口可信 IP 出网）');

        Log::info('[WechatWork] 租户企微出口代理已更新', [
            'tenant_id' => $tenantId,
            'enabled' => $config['enabled'],
            'host' => $config['host'] ?? '',
        ]);

        return response()->json(['success' => true, 'message' => '企微出口代理配置已保存']);
    }

    // ==================================================================
    // 内部
    // ==================================================================

    private function validateProvider(Request $request, ?int $ignoreId = null): array
    {
        // 系统级（tenant_id=null）内 suite_id 唯一
        $unique = Rule::unique('service_providers', 'suite_id')
            ->where(fn ($q) => $q->whereNull('tenant_id'));
        if ($ignoreId !== null) {
            $unique->ignore($ignoreId, 'service_provider_id');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'provider_corp_id' => 'nullable|string|max:64',
            // 服务商密钥：代开发模式生成授权二维码（get_customized_auth_url）需换 provider_access_token
            'provider_secret' => 'nullable|string|max:2000',
            // suite_id 可空：预注册阶段（URL 验证仅需服务商企业 ID + 回调凭证），模板创建成功后补录
            'suite_id' => ['nullable', 'string', 'max:64', $unique],
            'suite_secret' => 'nullable|string|max:2000',
            'callback_token' => 'nullable|string|max:255',
            'encoding_aes_key' => 'nullable|string|max:255',
            'callback_url' => 'nullable|string|max:500|url',
            // 模板级应用回调凭证：企微「创建代开发应用模板」生成的 Token/AESKey，
            // 「开始代开发应用」时自动带出到企业应用回调配置，所有租户共用
            'app_callback_token' => 'nullable|string|max:255',
            'app_encoding_aes_key' => 'nullable|string|max:255',
            'status' => 'sometimes|in:' . implode(',', ServiceProvider::STATUSES),
            'metadata' => 'sometimes|nullable|array',
            // 代开发模板权限集：服务商在企微后台勾选后在此声明，租户扫码授权即全部获得
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|max:64',
        ]);

        // 权限集合并进 metadata.template_permissions（不新增列，随服务商凭证一同存取）
        if (array_key_exists('permissions', $validated)) {
            $metadata = $validated['metadata'] ?? [];
            $metadata['template_permissions'] = array_values(array_unique($validated['permissions']));
            $validated['metadata'] = $metadata;
            unset($validated['permissions']);
        }

        // 空串归一为 null（数据库可空列，避免存 '' 造成解析歧义）
        if (($validated['suite_id'] ?? null) === '') {
            $validated['suite_id'] = null;
        }

        return $validated;
    }

    /**
     * 序列化服务商记录（suite_secret / encoding_aes_key 永不出库：有值返回掩码）
     */
    private function presentProvider(ServiceProvider $provider): array
    {
        return [
            'service_provider_id' => $provider->service_provider_id,
            'name' => $provider->name,
            'provider_corp_id' => $provider->provider_corp_id,
            'provider_secret' => $provider->getRawOriginal('provider_secret') ? self::SECRET_MASK : '',
            'suite_id' => $provider->suite_id,
            'suite_secret' => $provider->getRawOriginal('suite_secret') ? self::SECRET_MASK : '',
            'callback_token' => $provider->callback_token,
            'encoding_aes_key' => $provider->getRawOriginal('encoding_aes_key') ? self::SECRET_MASK : '',
            'callback_url' => $provider->callback_url,
            'app_callback_token' => $provider->app_callback_token,
            'app_encoding_aes_key' => $provider->getRawOriginal('app_encoding_aes_key') ? self::SECRET_MASK : '',
            'status' => $provider->status,
            'metadata' => $provider->metadata,
            // 模板权限集（key 列表，展示名见 ServiceProvider::TEMPLATE_PERMISSIONS）
            'permissions' => $provider->metadata['template_permissions'] ?? [],
            'created_at' => $provider->created_at,
            'updated_at' => $provider->updated_at,
        ];
    }
}
