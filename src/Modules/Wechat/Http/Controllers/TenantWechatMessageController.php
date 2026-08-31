<?php

namespace MultiTenantSaas\Modules\Wechat\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Wechat\Models\WechatMessageLog;
use MultiTenantSaas\Modules\Wechat\Models\WechatMessageTemplate;
use MultiTenantSaas\Modules\Wechat\Services\WechatMessageService;

/**
 * 租户服务号消息能力控制器（模板消息 / 客服消息）
 *
 * 链路：公众号后台选用模板（仅认证服务号）→ console 登记模板（template_key
 * 映射微信 template_id）→ 业务方按 template_key + 用户 openid 发送模板消息；
 * 客服文本经 custom/send 发送（微信侧校验 48 小时互动窗口）。
 *
 * 凭证双轨（component 授权优先 / self 自建兜底），失败码完整落发送记录。
 */
class TenantWechatMessageController extends Controller
{
    public function __construct(
        private readonly WechatMessageService $messages,
    ) {}

    /**
     * 消息能力状态（console 租户端，只读）
     */
    public function status(): JsonResponse
    {
        $tenantId = TenantContext::getId();

        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => '无法识别租户上下文'], 400);
        }

        return response()->json(['success' => true, 'data' => [
            'credential_mode' => $this->messages->credentialMode($tenantId),
            'template_count' => WechatMessageTemplate::query()->where('tenant_id', $tenantId)->active()->count(),
        ]]);
    }

    /**
     * 模板登记列表（console 租户端）
     */
    public function templates(): JsonResponse
    {
        $tenantId = TenantContext::getId();

        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => '无法识别租户上下文'], 400);
        }

        $templates = WechatMessageTemplate::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('message_template_id')
            ->get()
            ->map(fn (WechatMessageTemplate $t) => [
                'message_template_id' => $t->message_template_id,
                'template_key' => $t->template_key,
                'template_id' => $t->template_id,
                'title' => $t->title,
                'content_example' => $t->content_example,
                'status' => $t->status,
                'created_at' => $t->created_at,
            ]);

        return response()->json(['success' => true, 'data' => $templates]);
    }

    /**
     * 登记模板（console 租户端；template_key 租户内唯一）
     */
    public function storeTemplate(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getId();

        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => '无法识别租户上下文'], 400);
        }

        $validated = $request->validate([
            'template_key' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'template_id' => ['required', 'string', 'max:64'],
            'title' => ['nullable', 'string', 'max:128'],
            'content_example' => ['nullable', 'array'],
        ]);

        if (WechatMessageTemplate::query()
            ->where('tenant_id', $tenantId)
            ->where('template_key', $validated['template_key'])
            ->exists()) {
            return response()->json(['success' => false, 'message' => 'template_key 已存在（同一租户内唯一）'], 422);
        }

        $template = new WechatMessageTemplate;
        $template->tenant_id = $tenantId;
        $template->template_key = $validated['template_key'];
        $template->template_id = $validated['template_id'];
        $template->title = $validated['title'] ?? null;
        $template->content_example = $validated['content_example'] ?? null;
        $template->save();

        return response()->json(['success' => true, 'data' => [
            'message_template_id' => $template->message_template_id,
            'template_key' => $template->template_key,
            'template_id' => $template->template_id,
        ]], 201);
    }

    /**
     * 删除模板登记（console 租户端）
     */
    public function destroyTemplate(int $id): JsonResponse
    {
        $tenantId = TenantContext::getId();

        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => '无法识别租户上下文'], 400);
        }

        $deleted = WechatMessageTemplate::query()
            ->where('tenant_id', $tenantId)
            ->where('message_template_id', $id)
            ->delete();

        if ($deleted === 0) {
            return response()->json(['success' => false, 'message' => '模板登记不存在'], 404);
        }

        return response()->json(['success' => true, 'message' => '已删除']);
    }

    /**
     * 测试发送模板消息（console 租户端，直接调微信）
     */
    public function sendTemplate(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getId();

        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => '无法识别租户上下文'], 400);
        }

        $validated = $request->validate([
            'openid' => ['required', 'string', 'max:64'],
            'template_key' => ['required', 'string', 'max:64'],
            'data' => ['nullable', 'array'],
            'url' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $result = $this->messages->sendTemplate(
                $tenantId,
                $validated['openid'],
                $validated['template_key'],
                $validated['data'] ?? [],
                $validated['url'] ?? null,
            );
        } catch (\Throwable $e) {
            Log::warning('[WechatMessage] 发送模板消息失败', [
                'tenant_id' => $tenantId,
                'template_key' => $validated['template_key'],
                'error' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => "微信侧拒绝发送 [{$result['errcode']}]: {$result['errmsg']}（详见发送记录）",
                'data' => ['log_id' => $result['log_id']],
            ], 422);
        }

        return response()->json(['success' => true, 'data' => [
            'msgid' => $result['msgid'],
            'log_id' => $result['log_id'],
        ]]);
    }

    /**
     * 发送客服文本消息（console 租户端，直接调微信）
     */
    public function sendCustom(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getId();

        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => '无法识别租户上下文'], 400);
        }

        $validated = $request->validate([
            'openid' => ['required', 'string', 'max:64'],
            'content' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $result = $this->messages->sendCustomText($tenantId, $validated['openid'], $validated['content']);
        } catch (\Throwable $e) {
            Log::warning('[WechatMessage] 发送客服消息失败', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'message' => "微信侧拒绝发送 [{$result['errcode']}]: {$result['errmsg']}（客服消息需用户 48 小时内与公众号有互动）",
                'data' => ['log_id' => $result['log_id']],
            ], 422);
        }

        return response()->json(['success' => true, 'data' => [
            'msgid' => $result['msgid'],
            'log_id' => $result['log_id'],
        ]]);
    }

    /**
     * 发送记录（console 租户端，分页，可按类型/状态过滤）
     */
    public function logs(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getId();

        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => '无法识别租户上下文'], 400);
        }

        $query = WechatMessageLog::query()->where('tenant_id', $tenantId);

        if ($request->filled('type')) {
            $query->where('message_type', $request->string('type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $logs = $query->orderByDesc('message_log_id')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => collect($logs->items())->map(fn (WechatMessageLog $log) => [
                'message_log_id' => $log->message_log_id,
                'message_type' => $log->message_type,
                'template_key' => $log->template_key,
                'openid' => $log->openid,
                'content' => $log->content,
                'msg_id' => $log->msg_id,
                'status' => $log->status,
                'error_code' => $log->error_code,
                'error_message' => $log->error_message,
                'sent_at' => $log->sent_at,
                'created_at' => $log->created_at,
            ]),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
