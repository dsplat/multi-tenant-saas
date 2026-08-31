<?php

namespace MultiTenantSaas\Modules\Wechat\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use MultiTenantSaas\Modules\Wechat\Models\Authorization;
use MultiTenantSaas\Modules\Wechat\Models\ComponentProvider;
use MultiTenantSaas\Modules\Wechat\Services\WechatComponentService;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 平台管理后台 - 微信第三方平台组件配置控制器
 *
 * 管理组件凭证（wechat_component_providers 系统级记录）、连接测试、已授权租户列表。
 * 权限：沿用 setting.view / setting.update（与系统设置一致，同 AdminServiceProviderController）。
 */
class AdminComponentProviderController extends Controller
{
    /** component_secret / encoding_aes_key 掩码（列表返回/回存跳过，同 SystemSetting 安全模式） */
    private const SECRET_MASK = '********';

    public function __construct(
        private readonly WechatComponentService $component,
    ) {}

    // ==================================================================
    // 组件凭证 CRUD
    // ==================================================================

    public function providerIndex(): JsonResponse
    {
        $providers = ComponentProvider::query()
            ->whereNull('tenant_id')
            ->orderBy('component_provider_id')
            ->get()
            ->map(fn (ComponentProvider $p) => $this->presentProvider($p))
            ->values();

        return response()->json(['success' => true, 'data' => $providers]);
    }

    public function providerStore(Request $request): JsonResponse
    {
        $validated = $this->validateProvider($request);

        $provider = new ComponentProvider($validated);
        $provider->tenant_id = null; // 系统级配置
        $provider->save();

        return response()->json(['success' => true, 'data' => $this->presentProvider($provider)], 201);
    }

    public function providerUpdate(Request $request, int $providerId): JsonResponse
    {
        $provider = ComponentProvider::query()->whereNull('tenant_id')->find($providerId);

        if ($provider === null) {
            return response()->json(['success' => false, 'message' => trans('common.not_found')], 404);
        }

        $validated = $this->validateProvider($request, $providerId);

        // 掩码/空值 = 未修改，跳过回存避免覆盖真实密钥（encoding_aes_key 同属加密存储）
        foreach (['component_secret', 'encoding_aes_key'] as $field) {
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
        $provider = ComponentProvider::query()->whereNull('tenant_id')->find($providerId);

        if ($provider === null) {
            return response()->json(['success' => false, 'message' => trans('common.not_found')], 404);
        }

        $authorizationCount = TenantScope::allowUnscoped(fn () => Authorization::query()
            ->where('component_provider_id', $providerId)
            ->where('status', Authorization::STATUS_AUTHORIZED)
            ->count());

        if ($authorizationCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "仍有 {$authorizationCount} 个租户持有该组件的授权，请先在租户侧解除授权后删除",
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
        $provider = ComponentProvider::query()->whereNull('tenant_id')->find($providerId);

        if ($provider === null) {
            return response()->json(['success' => false, 'message' => trans('common.not_found')], 404);
        }

        try {
            $result = $this->component->testComponentToken($provider);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => '连接失败：' . $e->getMessage(),
                'data' => ['component_appid' => $provider->component_appid],
            ], 502);
        }

        return response()->json(['success' => true, 'data' => [
            'component_appid' => $provider->component_appid,
            'access_token_prefix' => $result['access_token'],
            'expires_in' => $result['expires_in'],
        ]]);
    }

    public function authorizations(): JsonResponse
    {
        // admin 为平台级视角（platform Operator 请求无租户上下文），
        // TenantScope fail-closed 会拦截为 WHERE 1=0，需显式豁免（同 WechatWork 先例）
        $rows = TenantScope::allowUnscoped(fn () => Authorization::query()
            ->leftJoin('tenants', 'tenants.tenant_id', '=', 'wechat_authorizations.tenant_id')
            ->orderByDesc('wechat_authorizations.updated_at')
            ->get([
                'wechat_authorizations.authorization_id',
                'wechat_authorizations.tenant_id',
                'wechat_authorizations.component_provider_id',
                'wechat_authorizations.authorizer_appid',
                'wechat_authorizations.authorizer_type',
                'wechat_authorizations.nickname',
                'wechat_authorizations.status',
                'wechat_authorizations.authorized_at',
                'wechat_authorizations.revoked_at',
                'tenants.name as tenant_name',
                'tenants.domain as tenant_domain',
            ])
            ->map(function ($row) {
                $row->authorizer_type_label = $row->authorizer_type === Authorization::TYPE_MINI_PROGRAM
                    ? '小程序'
                    : '公众号';

                return $row;
            })
            ->values());

        return response()->json(['success' => true, 'data' => $rows]);
    }

    // ==================================================================
    // 内部
    // ==================================================================

    private function validateProvider(Request $request, ?int $ignoreId = null): array
    {
        // 系统级（tenant_id=null）内 component_appid 唯一
        $unique = Rule::unique('wechat_component_providers', 'component_appid')
            ->where(fn ($q) => $q->whereNull('tenant_id'));
        if ($ignoreId !== null) {
            $unique->ignore($ignoreId, 'component_provider_id');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'component_appid' => ['required', 'string', 'max:32', $unique],
            'component_secret' => 'nullable|string|max:2000',
            'component_token' => 'nullable|string|max:255',
            'encoding_aes_key' => 'nullable|string|max:255',
            'callback_url' => 'nullable|string|max:500|url',
            'status' => 'sometimes|in:' . implode(',', ComponentProvider::STATUSES),
            'metadata' => 'sometimes|nullable|array',
            // 组件权限集：平台在开放平台第三方平台后台配置后在此声明，租户授权即全部获得
            'permissions' => 'nullable|array',
            'permissions.*' => 'string|max:64',
        ]);

        // 权限集合并进 metadata.permissions（不新增列，随组件凭证一同存取）
        if (array_key_exists('permissions', $validated)) {
            $metadata = $validated['metadata'] ?? [];
            $metadata['permissions'] = array_values(array_unique($validated['permissions']));
            $validated['metadata'] = $metadata;
            unset($validated['permissions']);
        }

        // 回调 URL 未填时自动带出平台统一地址（第三方平台「消息与事件接收URL」）
        if (empty($validated['callback_url'])) {
            $validated['callback_url'] = $this->component->callbackUrl();
        }

        return $validated;
    }

    /**
     * 序列化组件记录（component_secret / encoding_aes_key 永不出库：有值返回掩码）
     */
    private function presentProvider(ComponentProvider $provider): array
    {
        return [
            'component_provider_id' => $provider->component_provider_id,
            'name' => $provider->name,
            'component_appid' => $provider->component_appid,
            'component_secret' => $provider->getRawOriginal('component_secret') ? self::SECRET_MASK : '',
            'component_token' => $provider->component_token,
            'encoding_aes_key' => $provider->getRawOriginal('encoding_aes_key') ? self::SECRET_MASK : '',
            'callback_url' => $provider->callback_url,
            'status' => $provider->status,
            'metadata' => $provider->metadata,
            // 权限集（key 列表，展示名见 ComponentProvider::TEMPLATE_PERMISSIONS）
            'permissions' => $provider->metadata['permissions'] ?? [],
            'created_at' => $provider->created_at,
            'updated_at' => $provider->updated_at,
        ];
    }
}
