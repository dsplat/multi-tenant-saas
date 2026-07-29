<?php

namespace MultiTenantSaas\Modules\Ibot\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Ibot\Models\Ibot;
use MultiTenantSaas\Modules\Ibot\Models\OperatorIbotBinding;
use MultiTenantSaas\Modules\Ibot\Services\Channels\TelegramChannel;
use MultiTenantSaas\Modules\Ibot\Services\IbotBindingService;

/**
 * 随身助理绑定（operator 个人操作，无需 RBAC 管理权限）
 *
 * 控制台生成绑定码 → operator 在 IM 中发送绑定码完成绑定（docs/ibot.md 第四节）。
 */
class IbotBindingController extends Controller
{
    use AuthorizesTenantAccess;

    /**
     * 可绑定的机器人列表（active）
     */
    public function indexIbots(Request $request, int $tenantId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $ibots = Ibot::where('tenant_id', $tenantId)
            ->where('status', Ibot::STATUS_ACTIVE)
            ->get(['ibot_id', 'channel_type', 'transport', 'name', 'agent_id', 'status']);

        return response()->json(['success' => true, 'data' => $ibots]);
    }

    /**
     * 为当前 operator 生成一次性绑定码
     */
    public function generateBindCode(Request $request, int $tenantId, int $ibotId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        if (! config('ai.ibot.enabled', false)) {
            return response()->json(['success' => false, 'message' => '随身助理功能未启用。'], 403);
        }

        $ibot = Ibot::where('ibot_id', $ibotId)
            ->where('tenant_id', $tenantId)
            ->where('status', Ibot::STATUS_ACTIVE)
            ->firstOrFail();

        $operatorId = (int) $request->user()->operator_id;
        $code = app(IbotBindingService::class)->generateBindCode($operatorId, $ibot);

        // Telegram 可生成 t.me deep link（前端做成二维码）
        $bindLink = null;
        if ($ibot->channel_type === Ibot::CHANNEL_TELEGRAM) {
            $bindLink = app(TelegramChannel::class)->bindLink($ibot, $code);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'code' => $code,
                'bind_link' => $bindLink,
                'expires_in' => (int) config('ai.ibot.bind_code_ttl', 600),
            ],
        ]);
    }

    /**
     * 当前 operator 的绑定列表
     */
    public function myBindings(Request $request, int $tenantId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $bindings = OperatorIbotBinding::where('tenant_id', $tenantId)
            ->where('operator_id', $request->user()->operator_id)
            ->with('ibot:ibot_id,channel_type,name,status')
            ->get();

        return response()->json(['success' => true, 'data' => $bindings]);
    }

    /**
     * 解除当前 operator 的绑定
     */
    public function revokeBinding(Request $request, int $tenantId, int $bindingId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $binding = OperatorIbotBinding::where('binding_id', $bindingId)
            ->where('tenant_id', $tenantId)
            ->where('operator_id', $request->user()->operator_id)
            ->firstOrFail();

        $binding->update(['status' => OperatorIbotBinding::STATUS_REVOKED]);

        return response()->json(['success' => true]);
    }
}
