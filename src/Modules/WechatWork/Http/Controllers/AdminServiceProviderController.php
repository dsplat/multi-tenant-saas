<?php

namespace MultiTenantSaas\Modules\WechatWork\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\WechatWork\Models\ServiceProvider;
use MultiTenantSaas\Modules\WechatWork\Models\WechatWorkAuthorization;
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

        // 掩码/空值 = 未修改，跳过回存避免覆盖真实密钥
        foreach (['suite_secret', 'encoding_aes_key'] as $field) {
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
        $rows = WechatWorkAuthorization::query()
            ->leftJoin('tenants', 'tenants.tenant_id', '=', 'wechat_work_authorizations.tenant_id')
            ->orderByDesc('wechat_work_authorizations.updated_at')
            ->get([
                'wechat_work_authorizations.authorization_id',
                'wechat_work_authorizations.tenant_id',
                'wechat_work_authorizations.corp_id',
                'wechat_work_authorizations.agent_id',
                'wechat_work_authorizations.status',
                'wechat_work_authorizations.authorized_at',
                'wechat_work_authorizations.revoked_at',
                'tenants.name as tenant_name',
                'tenants.domain as tenant_domain',
            ]);

        return response()->json(['success' => true, 'data' => $rows]);
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
            'status' => $provider->status,
            'metadata' => $provider->metadata,
            // 模板权限集（key 列表，展示名见 ServiceProvider::TEMPLATE_PERMISSIONS）
            'permissions' => $provider->metadata['template_permissions'] ?? [],
            'created_at' => $provider->created_at,
            'updated_at' => $provider->updated_at,
        ];
    }
}
