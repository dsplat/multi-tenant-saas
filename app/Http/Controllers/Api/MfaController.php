<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Services\AuditService;
use MultiTenantSaas\Services\MfaService;
use MultiTenantSaas\Services\SessionService;

/**
 * @OA\Tag(name="多因素认证与会话", description="MFA 设备管理、恢复码、会话管理")
 */
class MfaController extends Controller
{
    public function __construct(
        private readonly MfaService $mfaService,
        private readonly SessionService $sessionService,
    ) {}

    // ========== TOTP 设备 ==========

    /**
     * TOTP 绑定第一步：生成密钥与 otpauth URI（不持久化）
     */
    public function setupTotp(Request $request)
    {
        $request->validate(['label' => 'nullable|string|max:100']);

        $user = $request->user();
        $secret = $this->mfaService->generateTotpSecret();
        $label = $request->input('label', $user->email ?: (string) $user->user_id);

        return response()->json([
            'success' => true,
            'message' => trans('auth.mfa_totp_setup'),
            'data' => [
                'secret' => $secret,
                'otpauth_uri' => $this->mfaService->getOtpauthUri($secret, $label),
            ],
        ]);
    }

    /**
     * TOTP 绑定第二步：校验验证码后持久化设备
     */
    public function confirmTotp(Request $request)
    {
        $request->validate([
            'secret' => 'required|string',
            'code' => 'required|string|size:6',
            'label' => 'nullable|string|max:100',
        ]);

        $user = $request->user();

        if (! $this->mfaService->verifyTotp($request->input('secret'), $request->input('code'))) {
            return response()->json([
                'success' => false,
                'message' => trans('auth.mfa_code_invalid'),
            ], 422);
        }

        $device = $this->mfaService->setupTotpDevice(
            $user->user_id,
            $request->input('secret'),
            $request->input('label', 'Authenticator')
        );

        AuditService::log('mfa_setup_totp', 'mfa', $user->user_id);

        return response()->json([
            'success' => true,
            'message' => trans('auth.mfa_device_bound'),
            'data' => $this->deviceToArray($device),
        ], 201);
    }

    // ========== 邮箱/短信设备 ==========

    /**
     * 绑定邮箱验证码设备
     */
    public function setupEmail(Request $request)
    {
        $request->validate(['label' => 'nullable|string|max:100']);

        $user = $request->user();
        $device = $this->mfaService->setupEmailDevice(
            $user->user_id,
            $request->input('label', 'Email')
        );

        AuditService::log('mfa_setup_email', 'mfa', $user->user_id);

        return response()->json([
            'success' => true,
            'message' => trans('auth.mfa_device_bound'),
            'data' => $this->deviceToArray($device),
        ], 201);
    }

    /**
     * 绑定短信验证码设备
     */
    public function setupSms(Request $request)
    {
        $request->validate([
            'phone' => 'required|string|max:20',
            'label' => 'nullable|string|max:100',
        ]);

        $user = $request->user();
        $device = $this->mfaService->setupSmsDevice(
            $user->user_id,
            $request->input('phone'),
            $request->input('label', 'SMS')
        );

        AuditService::log('mfa_setup_sms', 'mfa', $user->user_id);

        return response()->json([
            'success' => true,
            'message' => trans('auth.mfa_device_bound'),
            'data' => $this->deviceToArray($device),
        ], 201);
    }

    // ========== 验证码发送 ==========

    /**
     * 发送邮箱验证码
     */
    public function sendEmailCode(Request $request)
    {
        $user = $request->user();
        $code = $this->mfaService->sendEmailCode($user->user_id);

        return response()->json([
            'success' => true,
            'message' => trans('auth.mfa_code_sent'),
        ]);
    }

    /**
     * 发送短信验证码
     */
    public function sendSmsCode(Request $request)
    {
        $user = $request->user();
        $code = $this->mfaService->sendSmsCode($user->user_id);

        return response()->json([
            'success' => true,
            'message' => trans('auth.mfa_code_sent'),
        ]);
    }

    // ========== 设备管理 ==========

    /**
     * 列出当前用户的 MFA 设备
     */
    public function devices(Request $request)
    {
        $devices = $this->mfaService->listDevices($request->user()->user_id);

        return response()->json([
            'success' => true,
            'data' => $devices->map(fn ($d) => $this->deviceToArray($d))->values(),
        ]);
    }

    /**
     * 解绑 MFA 设备
     */
    public function destroyDevice(Request $request, int $deviceId)
    {
        $ok = $this->mfaService->deleteDevice($request->user()->user_id, $deviceId);

        if (! $ok) {
            return response()->json([
                'success' => false,
                'message' => trans('common.not_found'),
            ], 404);
        }

        AuditService::log('mfa_device_delete', 'mfa', $request->user()->user_id);

        return response()->json(['success' => true, 'message' => trans('auth.mfa_device_unbound')]);
    }

    /**
     * 重命名 MFA 设备
     */
    public function renameDevice(Request $request, int $deviceId)
    {
        $request->validate(['label' => 'required|string|max:100']);

        $device = $this->mfaService->renameDevice(
            $request->user()->user_id,
            $deviceId,
            $request->input('label')
        );

        if (! $device) {
            return response()->json([
                'success' => false,
                'message' => trans('common.not_found'),
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->deviceToArray($device),
        ]);
    }

    /**
     * 设为主设备
     */
    public function setPrimary(Request $request, int $deviceId)
    {
        $device = $this->mfaService->setPrimaryDevice($request->user()->user_id, $deviceId);

        if (! $device) {
            return response()->json([
                'success' => false,
                'message' => trans('common.not_found'),
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->deviceToArray($device),
        ]);
    }

    // ========== 恢复码 ==========

    /**
     * 生成新恢复码（明文仅返回一次）
     */
    public function generateRecoveryCodes(Request $request)
    {
        $codes = $this->mfaService->regenerateRecoveryCodes($request->user()->user_id);

        AuditService::log('mfa_recovery_generate', 'mfa', $request->user()->user_id);

        return response()->json([
            'success' => true,
            'message' => trans('auth.mfa_recovery_codes_generated'),
            'data' => [
                'codes' => $codes,
            ],
        ]);
    }

    /**
     * 恢复码使用状态
     */
    public function recoveryCodeStatus(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => $this->mfaService->getRecoveryCodeStatus($request->user()->user_id),
        ]);
    }

    // ========== 会话管理 ==========

    /**
     * 活跃会话列表
     */
    public function sessions(Request $request)
    {
        $sessions = $this->sessionService->listSessions($request->user()->user_id);

        return response()->json([
            'success' => true,
            'data' => $sessions->map(fn ($s) => $this->sessionToArray($s, $request->user()->currentAccessToken()?->id))->values(),
        ]);
    }

    /**
     * 强制下线单个会话
     */
    public function revokeSession(Request $request, int $sessionId)
    {
        $ok = $this->sessionService->revokeSession($request->user()->user_id, $sessionId);

        if (! $ok) {
            return response()->json([
                'success' => false,
                'message' => trans('common.not_found'),
            ], 404);
        }

        AuditService::log('session_revoke', 'session', $request->user()->user_id);

        return response()->json(['success' => true, 'message' => trans('auth.session_revoked')]);
    }

    /**
     * 强制下线所有其他会话
     */
    public function revokeAllSessions(Request $request)
    {
        $currentTokenId = $request->user()->currentAccessToken()?->id;
        $count = $this->sessionService->revokeAllSessions($request->user()->user_id, $currentTokenId);

        AuditService::log('session_revoke_all', 'session', $request->user()->user_id);

        return response()->json([
            'success' => true,
            'message' => trans('auth.sessions_revoked'),
            'data' => ['revoked_count' => $count],
        ]);
    }

    // ========== 私有辅助 ==========

    /**
     * MFA 设备转数组（隐藏 secret）
     */
    private function deviceToArray($device): array
    {
        return [
            'mfa_device_id' => $device->mfa_device_id,
            'type' => $device->type,
            'label' => $device->label,
            'is_primary' => (bool) $device->is_primary,
            'is_verified' => (bool) $device->is_verified,
            'last_used_at' => $device->last_used_at,
        ];
    }

    /**
     * 会话转数组（标记当前会话）
     */
    private function sessionToArray($session, ?int $currentTokenId): array
    {
        return [
            'user_session_id' => $session->user_session_id,
            'ip_address' => $session->ip_address,
            'device_info' => $session->device_info,
            'login_at' => $session->login_at,
            'last_active_at' => $session->last_active_at,
            'location' => $session->location,
            'is_anomalous' => (bool) $session->is_anomalous,
            'is_current' => $currentTokenId !== null && $session->token_id === $currentTokenId,
        ];
    }
}
