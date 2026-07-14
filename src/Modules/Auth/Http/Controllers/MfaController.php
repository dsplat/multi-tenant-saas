<?php

namespace MultiTenantSaas\Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Services\MfaService;
use MultiTenantSaas\Modules\Auth\Services\SessionService;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantUser;

class MfaController extends Controller
{
    public function __construct(
        protected MfaService $mfaService,
        protected SessionService $sessionService,
    ) {}

    /**
     * 确保用户属于当前租户
     */
    private function ensureTenantMembership(Request $request): void
    {
        $tenantId = TenantContext::getId();

        if ($tenantId) {
            $exists = TenantUser::where('tenant_id', $tenantId)
                ->where('user_id', $request->user()->user_id)
                ->where('is_active', true)
                ->exists();

            if (! $exists) {
                abort(403, trans('auth.not_tenant_member'));
            }
        }
    }

    public function setupTotp(Request $request): JsonResponse
    {
        $secret = $this->mfaService->generateTotpSecret();
        $label = $request->user()->email ?? 'User';
        $uri = $this->mfaService->getOtpauthUri($secret, $label);

        return response()->json([
            'success' => true,
            'data' => ['secret' => $secret, 'otpauth_url' => $uri],
        ]);
    }

    public function confirmTotp(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string', 'secret' => 'required|string']);

        $valid = $this->mfaService->verifyTotp($request->secret, $request->code);

        if (! $valid) {
            return response()->json(['success' => false, 'message' => trans('auth.mfa_invalid_code')], 422);
        }

        $device = $this->mfaService->setupTotpDevice(
            $request->user()->user_id,
            $request->secret,
            $request->input('label', 'TOTP')
        );

        return response()->json(['success' => true, 'data' => ['device_id' => $device->mfa_device_id]]);
    }

    public function sendEmailCode(Request $request): JsonResponse
    {
        $this->mfaService->sendEmailCode($request->user()->user_id);

        return response()->json(['success' => true, 'message' => trans('auth.mfa_code_sent')]);
    }

    public function sendSmsCode(Request $request): JsonResponse
    {
        $this->mfaService->sendSmsCode($request->user()->user_id);

        return response()->json(['success' => true, 'message' => trans('auth.mfa_code_sent')]);
    }

    public function devices(Request $request): JsonResponse
    {
        $this->ensureTenantMembership($request);

        $devices = $this->mfaService->listDevices($request->user()->user_id);

        return response()->json(['success' => true, 'data' => ['devices' => $devices]]);
    }

    public function destroyDevice(Request $request, int $deviceId): JsonResponse
    {
        $this->ensureTenantMembership($request);

        $this->mfaService->deleteDevice($request->user()->user_id, $deviceId);

        return response()->json(['success' => true, 'message' => trans('auth.mfa_device_removed')]);
    }

    public function renameDevice(Request $request, int $deviceId): JsonResponse
    {
        $this->ensureTenantMembership($request);

        $request->validate(['name' => 'required|string|max:50']);

        $this->mfaService->renameDevice($request->user()->user_id, $deviceId, $request->name);

        return response()->json(['success' => true, 'message' => trans('auth.mfa_device_renamed')]);
    }

    public function setPrimary(Request $request, int $deviceId): JsonResponse
    {
        $this->ensureTenantMembership($request);

        $this->mfaService->setPrimaryDevice($request->user()->user_id, $deviceId);

        return response()->json(['success' => true, 'message' => trans('auth.mfa_device_primary_set')]);
    }

    public function generateRecoveryCodes(Request $request): JsonResponse
    {
        $this->ensureTenantMembership($request);

        $codes = $this->mfaService->regenerateRecoveryCodes($request->user()->user_id);

        return response()->json(['success' => true, 'data' => ['recovery_codes' => $codes]]);
    }

    public function recoveryCodeStatus(Request $request): JsonResponse
    {
        $this->ensureTenantMembership($request);

        $status = $this->mfaService->getRecoveryCodeStatus($request->user()->user_id);

        return response()->json(['success' => true, 'data' => $status]);
    }

    public function sessions(Request $request): JsonResponse
    {
        $this->ensureTenantMembership($request);

        $sessions = $this->sessionService->listSessions($request->user()->user_id);

        return response()->json(['success' => true, 'data' => ['sessions' => $sessions]]);
    }

    public function revokeSession(Request $request, int $sessionId): JsonResponse
    {
        $this->ensureTenantMembership($request);

        $this->sessionService->revokeSession($request->user()->user_id, $sessionId);

        return response()->json(['success' => true, 'message' => trans('auth.session_revoked')]);
    }

    public function revokeAllSessions(Request $request): JsonResponse
    {
        $this->ensureTenantMembership($request);

        $count = $this->sessionService->revokeAllSessions($request->user()->user_id);

        return response()->json(['success' => true, 'data' => ['revoked' => $count]]);
    }
}
