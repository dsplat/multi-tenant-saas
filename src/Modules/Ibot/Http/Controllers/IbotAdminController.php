<?php

namespace MultiTenantSaas\Modules\Ibot\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Models\OperatorIbotBinding;
use MultiTenantSaas\Modules\Ibot\Services\WechatWorkSuiteGuard;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\WechatWork\Services\WechatWorkSuiteService;

/**
 * ibot 频道配置管理（租户管理端，权限 setting.update——与 OAuth/邮件配置同级）
 *
 * 凭证脱敏铁律：任何响应不回明文，仅 configured_fields + 尾 4 位掩码；
 * 更新时凭证局部合并——空值/掩码值不覆盖既有明文。
 */
class IbotAdminController extends Controller
{
    // 掩码前缀（前端提交时原样回传即视为「未修改」）
    private const MASK_PREFIX = '****';

    // 各频道允许写入的凭证字段白名单
    private const CREDENTIAL_FIELDS = [
        Ibot::CHANNEL_WECHAT_WORK => ['corp_id', 'corp_secret', 'agent_id', 'token', 'encoding_aes_key'],
        Ibot::CHANNEL_TELEGRAM => ['bot_token', 'bot_username'],
    ];

    /**
     * 全量列表（含 disabled，管理端视角）
     */
    public function index(Request $request): JsonResponse
    {
        $ibots = Ibot::withCount([
            'bindings as active_bindings_count' => fn ($query) => $query->where('status', OperatorIbotBinding::STATUS_ACTIVE),
        ])->orderBy('ibot_id')->get();

        return response()->json([
            'success' => true,
            'data' => $ibots->map(fn (Ibot $ibot) => $this->serialize($request, $ibot))->values(),
        ]);
    }

    /**
     * 创建 ibot（channel_type 限已实现频道，transport 固定 webhook）
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel_type' => 'required|string|in:' . implode(',', array_keys(self::CREDENTIAL_FIELDS)),
            'name' => 'required|string|max:100',
            'agent_id' => 'nullable|integer',
            'credentials' => 'required|array',
        ]);

        $credentials = $this->filterCredentials($validated['channel_type'], $validated['credentials']);
        $agentId = $validated['agent_id'] ?? null;

        // 企微渠道：凭证统一在「企业微信配置」页维护，此处未显式提供时自动带出
        // （suite 授权优先，其次自建 OAuth 配置），console 无需重复填写
        if ($validated['channel_type'] === Ibot::CHANNEL_WECHAT_WORK && empty($credentials['corp_id'])) {
            [$credentials, $agentId] = $this->inheritWechatWorkConfig($credentials, $agentId);
        }

        $ibot = Ibot::create([
            'tenant_id' => (int) TenantContext::getId(),
            'channel_type' => $validated['channel_type'],
            'transport' => Ibot::TRANSPORT_WEBHOOK,
            'name' => $validated['name'],
            'agent_id' => $agentId,
            'credentials' => $credentials,
            'status' => Ibot::STATUS_ACTIVE,
        ]);

        return response()->json(['success' => true, 'data' => $this->serialize($request, $ibot)], 201);
    }

    /**
     * 从租户企微配置带出凭证：suite 授权优先（corp_id + agent_id 取自授权记录），
     * 其次自建 OAuth 配置（wechatwork 组，旧 oauth.wechat_work_* 回退）
     *
     * @return array{0: array<string, string>, 1: ?int}
     */
    private function inheritWechatWorkConfig(array $credentials, ?int $agentId): array
    {
        $tenantId = (int) TenantContext::getId();

        $auths = app(WechatWorkSuiteService::class)->appAuthorizations($tenantId);
        if ($auths !== []) {
            $auth = $auths[0];
            $credentials['corp_id'] = (string) ($auth->corp_id ?? '');
            if ($agentId === null) {
                $agentId = $auth->agent_id ? (int) $auth->agent_id : null;
            }

            return [$credentials, $agentId];
        }

        $credentials['corp_id'] = (string) TenantSetting::get(
            $tenantId,
            'wechatwork',
            'corp_id',
            (string) TenantSetting::get($tenantId, 'oauth', 'wechat_work_corp_id', '')
        );
        $credentials['corp_secret'] = (string) TenantSetting::get($tenantId, 'wechatwork', 'secret', '');
        if ($agentId === null) {
            $configuredAgentId = (string) TenantSetting::get(
                $tenantId,
                'wechatwork',
                'agent_id',
                (string) TenantSetting::get($tenantId, 'oauth', 'wechat_work_agent_id', '')
            );
            $agentId = $configuredAgentId !== '' ? (int) $configuredAgentId : null;
        }

        return [$credentials, $agentId];
    }

    /**
     * 更新 name/agent_id/credentials（凭证局部合并）
     */
    public function update(Request $request, int $ibotId): JsonResponse
    {
        $ibot = Ibot::findOrFail($ibotId);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'agent_id' => 'nullable|integer',
            'credentials' => 'sometimes|array',
        ]);

        if (array_key_exists('name', $validated)) {
            $ibot->name = $validated['name'];
        }

        if (array_key_exists('agent_id', $validated)) {
            $ibot->agent_id = $validated['agent_id'];
        }

        if (array_key_exists('credentials', $validated)) {
            $incoming = $this->filterCredentials($ibot->channel_type, $validated['credentials']);
            // 局部合并：仅覆盖有效新值，既有字段保留
            $ibot->credentials = array_merge($ibot->credentials ?? [], $incoming);
        }

        $ibot->save();

        return response()->json(['success' => true, 'data' => $this->serialize($request, $ibot)]);
    }

    /**
     * active/disabled 切换
     */
    public function updateStatus(Request $request, int $ibotId): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:' . Ibot::STATUS_ACTIVE . ',' . Ibot::STATUS_DISABLED,
        ]);

        $ibot = Ibot::findOrFail($ibotId);
        $ibot->update(['status' => $validated['status']]);

        return response()->json(['success' => true, 'data' => $this->serialize($request, $ibot)]);
    }

    /**
     * 删除（存在 active 绑定时拒绝，避免误删导致通知断链）
     */
    public function destroy(Request $request, int $ibotId): JsonResponse
    {
        $ibot = Ibot::findOrFail($ibotId);

        $activeBindings = $ibot->bindings()->where('status', OperatorIbotBinding::STATUS_ACTIVE)->count();

        if ($activeBindings > 0) {
            return response()->json([
                'success' => false,
                'message' => "该机器人仍有 {$activeBindings} 个生效绑定，请先解除绑定再删除。",
            ], 422);
        }

        $ibot->bindings()->delete();
        $ibot->delete();

        return response()->json(['success' => true]);
    }

    /**
     * 凭证白名单过滤：只收本频道字段，剔除空值与掩码值（不覆盖既有）
     *
     * @return array<string, string>
     */
    private function filterCredentials(string $channelType, array $credentials): array
    {
        $allowed = self::CREDENTIAL_FIELDS[$channelType] ?? [];
        $filtered = [];

        foreach ($allowed as $field) {
            $value = $credentials[$field] ?? null;

            if (! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '' || str_starts_with($value, self::MASK_PREFIX)) {
                continue;
            }

            $filtered[$field] = $value;
        }

        return $filtered;
    }

    /**
     * 序列化（凭证脱敏：字段名数组 + 尾 4 位掩码）
     *
     * @return array<string, mixed>
     */
    private function serialize(Request $request, Ibot $ibot): array
    {
        $credentials = $ibot->credentials ?? [];

        $configured = [];
        $masked = [];

        foreach ($credentials as $field => $value) {
            $value = (string) $value;

            if ($value === '') {
                continue;
            }

            $configured[] = $field;
            $masked[$field] = self::MASK_PREFIX . substr($value, -4);
        }

        // 9.3 双轨：套件授权租户无需自建 corp_secret（前端据此切换引导文案）
        $wechatWork = $ibot->channel_type === Ibot::CHANNEL_WECHAT_WORK;
        $suiteAuthorized = $wechatWork ? WechatWorkSuiteGuard::authorized((int) $ibot->tenant_id) : false;

        return [
            'ibot_id' => $ibot->ibot_id,
            'channel_type' => $ibot->channel_type,
            'transport' => $ibot->transport,
            'name' => $ibot->name,
            'agent_id' => $ibot->agent_id,
            'status' => $ibot->status,
            'mode' => $wechatWork ? ($suiteAuthorized ? 'suite' : 'self') : null,
            'configured_fields' => $configured,
            'credentials_masked' => $masked,
            'webhook_url' => $this->webhookUrl($request, $ibot),
            'active_bindings_count' => (int) ($ibot->active_bindings_count
                ?? $ibot->bindings()->where('status', OperatorIbotBinding::STATUS_ACTIVE)->count()),
            'created_at' => $ibot->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * 回调 URL（按请求域名拼；企微专用——Telegram 走 long polling 无需回调）
     */
    private function webhookUrl(Request $request, Ibot $ibot): ?string
    {
        if ($ibot->channel_type !== Ibot::CHANNEL_WECHAT_WORK) {
            return null;
        }

        return $request->getSchemeAndHttpHost() . "/api/v1/ibot/webhook/wechat-work/{$ibot->ibot_id}";
    }
}
