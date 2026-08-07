<?php

namespace MultiTenantSaas\Modules\Ai\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use MultiTenantSaas\Modules\Ai\Models\AiModelAlias;
use MultiTenantSaas\Modules\Ai\Models\AiProvider;
use MultiTenantSaas\Modules\Ai\Models\AiTenantConfig;
use MultiTenantSaas\Modules\Ai\Services\AiModelCatalogService;
use MultiTenantSaas\Modules\Ai\Services\AiPlatformConfigService;
use MultiTenantSaas\Modules\Infrastructure\Models\SystemSetting;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 平台管理后台 - AI 配置控制器
 *
 * 管理模型别名映射、模型目录缓存同步、平台默认模型组、租户 AI 配置、
 * 提供商多源管理（ai_providers 系统级记录），
 * 以及 provider 连接测试（读取 DB 覆盖层，不落库）。
 *
 * 权限：沿用 setting.view / setting.update（与系统设置一致）。
 */
class AdminAiController extends Controller
{
    /** api_key 掩码（列表返回/回存跳过，同 SystemSetting 安全模式） */
    private const API_KEY_MASK = '********';

    /** 默认模型组允许写入的键（system_settings group='ai'） */
    private const DEFAULT_KEYS = [
        'default_chat_model',
        'default_completion_model',
        'default_embedding_model',
        'default_provider',
    ];

    // ==================================================================
    // 模型别名（ai_model_aliases，全局表）
    // ==================================================================

    public function aliasIndex(Request $request): JsonResponse
    {
        $query = AiModelAlias::query()->orderBy('alias');

        if ($keyword = trim((string) $request->query('keyword', ''))) {
            $query->where(function ($q) use ($keyword) {
                $q->where('alias', 'like', "%{$keyword}%")
                    ->orWhere('actual_model', 'like', "%{$keyword}%");
            });
        }

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    public function aliasStore(Request $request): JsonResponse
    {
        $validated = $this->validateAlias($request);

        $alias = AiModelAlias::create($validated);

        return response()->json(['success' => true, 'data' => $alias], 201);
    }

    public function aliasUpdate(Request $request, int $aliasId): JsonResponse
    {
        $alias = AiModelAlias::find($aliasId);

        if ($alias === null) {
            return response()->json(['success' => false, 'message' => trans('common.not_found')], 404);
        }

        $alias->update($this->validateAlias($request, $aliasId));

        return response()->json(['success' => true, 'data' => $alias->fresh()]);
    }

    public function aliasDestroy(int $aliasId): JsonResponse
    {
        $deleted = AiModelAlias::where('alias_id', $aliasId)->delete();

        if (! $deleted) {
            return response()->json(['success' => false, 'message' => trans('common.not_found')], 404);
        }

        return response()->json(['success' => true, 'message' => trans('common.deleted')]);
    }

    // ==================================================================
    // 模型目录（ai:models:sync 缓存）
    // ==================================================================

    public function catalog(AiModelCatalogService $catalog): JsonResponse
    {
        $providers = array_keys((array) config('ai.providers', []));

        $data = collect($providers)->mapWithKeys(function (string $provider) use ($catalog) {
            $models = $catalog->cachedModels($provider);

            return [$provider => [
                'cached' => $models !== null,
                'count' => $models !== null ? count($models) : 0,
                'models' => $models ?? [],
            ]];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function catalogSync(Request $request): JsonResponse
    {
        $provider = trim((string) $request->input('provider', ''));

        if ($provider !== '' && ! preg_match('/^[a-z0-9_]+$/', $provider)) {
            return response()->json(['success' => false, 'message' => trans('common.invalid_request')], 422);
        }

        // 直接调 service（ai:models:sync 命令仅 console 上下文注册，HTTP 内不可用）
        $catalog = app(AiModelCatalogService::class);
        $targets = $provider !== '' ? [$provider] : $catalog->syncableProviders();

        $result = [];
        foreach ($targets as $code) {
            $result[$code] = count($catalog->sync($code));
        }

        return response()->json(['success' => true, 'data' => ['synced' => $result]]);
    }

    // ==================================================================
    // 平台默认模型组（system_settings group='ai'）
    // ==================================================================

    public function defaultsIndex(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'default_chat_model' => AiPlatformConfigService::resolveTextDefault('chat', 'ai.text.default_chat_model', 'gpt-4o-mini'),
            'default_completion_model' => AiPlatformConfigService::resolveTextDefault('completion', 'ai.text.default_completion_model', 'gpt-4o-mini'),
            'default_embedding_model' => AiPlatformConfigService::resolveTextDefault('embedding', 'ai.text.default_embedding_model', 'text-embedding-3-small'),
            'default_provider' => AiPlatformConfigService::resolveDefaultProvider(),
        ]]);
    }

    public function defaultsUpdate(Request $request): JsonResponse
    {
        // 拒绝未知键，避免误配置静默落库
        $unknown = array_diff(array_keys($request->all()), self::DEFAULT_KEYS);
        if ($unknown !== []) {
            return response()->json([
                'success' => false,
                'message' => trans('common.invalid_request') . ': ' . implode(', ', $unknown),
            ], 422);
        }

        $validated = $request->validate([
            'default_chat_model' => 'sometimes|nullable|string|max:200',
            'default_completion_model' => 'sometimes|nullable|string|max:200',
            'default_embedding_model' => 'sometimes|nullable|string|max:200',
            'default_provider' => 'sometimes|nullable|string|max:100',
        ]);

        foreach (self::DEFAULT_KEYS as $key) {
            if (! array_key_exists($key, $validated)) {
                continue;
            }

            $value = trim((string) $validated[$key]);

            // 空值 = 清除 DB 覆盖，回退 env/config 引导层
            $value === ''
                ? SystemSetting::remove(AiPlatformConfigService::GROUP_DEFAULTS, $key)
                : SystemSetting::set(AiPlatformConfigService::GROUP_DEFAULTS, $key, $value);
        }

        AiPlatformConfigService::forgetCached();

        return $this->defaultsIndex();
    }

    // ==================================================================
    // 租户 AI 配置（ai_tenant_configs，跨租户管理需绕过租户作用域）
    // ==================================================================

    public function tenantIndex(): JsonResponse
    {
        $tenants = Tenant::query()
            ->orderBy('tenant_id')
            ->limit(200)
            ->get(['tenant_id', 'name', 'slug', 'status']);

        return response()->json(['success' => true, 'data' => $tenants]);
    }

    public function tenantConfigShow(int $tenantId): JsonResponse
    {
        $config = AiTenantConfig::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->first();

        return response()->json(['success' => true, 'data' => [
            'configured' => $config !== null,
            'config' => $config,
        ]]);
    }

    public function tenantConfigUpdate(Request $request, int $tenantId): JsonResponse
    {
        if (! Tenant::where('tenant_id', $tenantId)->exists()) {
            return response()->json(['success' => false, 'message' => trans('common.not_found')], 404);
        }

        $validated = $request->validate([
            'text_enabled' => 'sometimes|boolean',
            'image_enabled' => 'sometimes|boolean',
            'video_enabled' => 'sometimes|boolean',
            'monthly_budget_limit' => 'sometimes|numeric|min:0',
            'overage_action' => 'sometimes|in:' . implode(',', AiTenantConfig::OVERAGE_ACTIONS),
            'allowed_models' => 'sometimes|nullable|array',
            'allowed_models.*' => 'string|max:200',
        ]);

        $config = AiTenantConfig::withoutGlobalScope(TenantScope::class)
            ->firstOrNew(['tenant_id' => $tenantId]);

        $config->fill($validated);
        $config->tenant_id = $tenantId;
        $config->save();

        return response()->json(['success' => true, 'data' => $config]);
    }

    // ==================================================================
    // 提供商多源管理（ai_providers，系统级记录 tenant_id=null）
    // ==================================================================

    public function providerIndex(): JsonResponse
    {
        $providers = AiProvider::query()
            ->whereNull('tenant_id')
            ->orderBy('priority')
            ->orderBy('code')
            ->get()
            ->map(fn (AiProvider $p) => $this->presentProvider($p))
            ->values();

        return response()->json(['success' => true, 'data' => $providers]);
    }

    public function providerStore(Request $request): JsonResponse
    {
        $validated = $this->validateProvider($request);

        $provider = new AiProvider($validated);
        $provider->tenant_id = null; // 系统级配置
        $provider->save();

        AiPlatformConfigService::forgetCached($provider->code);

        return response()->json(['success' => true, 'data' => $this->presentProvider($provider)], 201);
    }

    public function providerUpdate(Request $request, int $providerId): JsonResponse
    {
        $provider = AiProvider::query()->whereNull('tenant_id')->find($providerId);

        if ($provider === null) {
            return response()->json(['success' => false, 'message' => trans('common.not_found')], 404);
        }

        $validated = $this->validateProvider($request, $providerId);

        // 掩码/空值 = 未修改，跳过回存避免覆盖真实密钥
        $apiKey = $validated['api_key'] ?? null;
        if (! is_string($apiKey) || $apiKey === '' || $apiKey === self::API_KEY_MASK) {
            unset($validated['api_key']);
        }

        $oldCode = $provider->code;
        $provider->fill($validated);
        $provider->save();

        AiPlatformConfigService::forgetCached($oldCode);
        if ($provider->code !== $oldCode) {
            AiPlatformConfigService::forgetCached($provider->code);
        }

        return response()->json(['success' => true, 'data' => $this->presentProvider($provider)]);
    }

    public function providerDestroy(int $providerId): JsonResponse
    {
        $provider = AiProvider::query()->whereNull('tenant_id')->find($providerId);

        if ($provider === null) {
            return response()->json(['success' => false, 'message' => trans('common.not_found')], 404);
        }

        $provider->delete();
        AiPlatformConfigService::forgetCached($provider->code);

        return response()->json(['success' => true, 'message' => trans('common.deleted')]);
    }

    // ==================================================================
    // Provider 连接测试（读 DB 覆盖层，不落库）
    // ==================================================================

    public function providerTest(string $code): JsonResponse
    {
        if (! preg_match('/^[a-z0-9_]+$/', $code)) {
            return response()->json(['success' => false, 'message' => trans('common.invalid_request')], 422);
        }

        $config = AiPlatformConfigService::resolveProviderConfig($code);
        $baseUrl = rtrim((string) ($config['url'] ?? $config['base_url'] ?? ''), '/');
        $apiKey = (string) ($config['key'] ?? $config['api_key'] ?? '');

        if ($baseUrl === '' || $apiKey === '') {
            return response()->json([
                'success' => false,
                'message' => "provider [{$code}] 未配置 base_url/api_key（env 或后台补录均可）",
            ], 422);
        }

        // 配置来源：ai_providers 表 / system_settings 覆盖 or env/config 引导层
        $source = 'env';
        try {
            if (AiPlatformConfigService::providerRecord($code) !== null
                || SystemSetting::getGroup('ai_provider_' . $code) !== []) {
                $source = 'db';
            }
        } catch (\Throwable $e) {
            // 表不可用 → 视为 env
        }

        $start = microtime(true);

        try {
            $response = Http::withToken($apiKey)->timeout(15)->get("{$baseUrl}/models");
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => "连接失败: {$e->getMessage()}",
                'data' => ['base_url' => $baseUrl, 'source' => $source],
            ], 502);
        }

        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        if (! $response->successful()) {
            return response()->json([
                'success' => false,
                'message' => "/models 返回 HTTP {$response->status()}",
                'data' => ['base_url' => $baseUrl, 'source' => $source, 'latency_ms' => $latencyMs],
            ], 502);
        }

        $count = collect((array) $response->json('data', []))
            ->pluck('id')
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->count();

        return response()->json(['success' => true, 'data' => [
            'base_url' => $baseUrl,
            'source' => $source,
            'model_count' => $count,
            'latency_ms' => $latencyMs,
        ]]);
    }

    // ==================================================================
    // 内部
    // ==================================================================

    private function validateAlias(Request $request, ?int $ignoreId = null): array
    {
        $unique = 'unique:ai_model_aliases,alias';
        if ($ignoreId !== null) {
            $unique .= ",{$ignoreId},alias_id";
        }

        return $request->validate([
            'alias' => ['required', 'string', 'max:200', $unique],
            'actual_model' => 'required|string|max:200',
            'provider' => 'nullable|string|max:100',
            'type' => 'required|in:' . implode(',', AiModelAlias::TYPES),
            'is_active' => 'sometimes|boolean',
            'is_deprecated' => 'sometimes|boolean',
            'description' => 'nullable|string|max:500',
        ]);
    }

    private function validateProvider(Request $request, ?int $ignoreId = null): array
    {
        // 系统级（tenant_id=null）内 code 唯一
        $unique = Rule::unique('ai_providers', 'code')
            ->where(fn ($q) => $q->whereNull('tenant_id'));
        if ($ignoreId !== null) {
            $unique->ignore($ignoreId, 'provider_id');
        }

        return $request->validate([
            'code' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', $unique],
            'name' => 'required|string|max:100',
            'base_url' => 'nullable|string|max:255',
            'api_key' => 'nullable|string|max:5000',
            'status' => 'sometimes|in:' . implode(',', AiProvider::STATUSES),
            'priority' => 'sometimes|integer|min:0|max:32767',
            'metadata' => 'sometimes|nullable|array',
        ]);
    }

    /**
     * 序列化提供商记录（api_key 永不出库：有值返回掩码，无值返回空串）
     */
    private function presentProvider(AiProvider $provider): array
    {
        return [
            'provider_id' => $provider->provider_id,
            'code' => $provider->code,
            'name' => $provider->name,
            'base_url' => $provider->base_url,
            'api_key' => $provider->getRawOriginal('api_key') ? self::API_KEY_MASK : '',
            'status' => $provider->status,
            'priority' => $provider->priority,
            'metadata' => $provider->metadata,
            'created_at' => $provider->created_at,
            'updated_at' => $provider->updated_at,
        ];
    }
}
