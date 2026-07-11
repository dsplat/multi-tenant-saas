<?php

namespace MultiTenantSaas\Modules\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use MultiTenantSaas\Services\MfaService;
use MultiTenantSaas\Services\SessionService;

class MfaController extends Controller
{
    public function __construct(
        protected MfaService $mfaService,
        protected SessionService $sessionService,
    ) {}

    public function setupTotp(Request $request): JsonResponse
    {
        $result = $this->mfaService->generateTotpSetup($request->user()->user_id);

        return response()->json(['success' => true, 'data' => $result]);
    }

    public function confirmTotp(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string']);

        $device = $this->mfaService->confirmTotpSetup(
            $request->user()->user_id,
            $request->code
        );

        if (! $device) {
            return response()->json(['success' => false, 'message' => trans('auth.mfa_invalid_code')], 422);
        }

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
        $devices = $this->mfaService->getDevices($request->user()->user_id);

        return response()->json(['success' => true, 'data' => ['devices' => $devices]]);
    }

    public function destroyDevice(Request $request, int $deviceId): JsonResponse
    {
        $this->mfaService->removeDevice($request->user()->user_id, $deviceId);

        return response()->json(['success' => true, 'message' => trans('auth.mfa_device_removed')]);
    }

    public function renameDevice(Request $request, int $deviceId): JsonResponse
    {
        $request->validate(['name' => 'required|string|max:50']);

        $this->mfaService->renameDevice($deviceId, $request->name);

        return response()->json(['success' => true, 'message' => trans('auth.mfa_device_renamed')]);
    }

    public function setPrimary(Request $request, int $deviceId): JsonResponse
    {
        $this->mfaService->setPrimaryDevice($request->user()->user_id, $deviceId);

        return response()->json(['success' => true, 'message' => trans('auth.mfa_device_primary_set')]);
    }

    public function generateRecoveryCodes(Request $request): JsonResponse
    {
        $codes = $this->mfaService->generateRecoveryCodes($request->user()->user_id);

        return response()->json(['success' => true, 'data' => ['recovery_codes' => $codes]]);
    }

    public function recoveryCodeStatus(Request $request): JsonResponse
    {
        $status = $this->mfaService->getRecoveryCodeStatus($request->user()->user_id);

        return response()->json(['success' => true, 'data' => $status]);
    }

    public function sessions(Request $request): JsonResponse
    {
        $sessions = $this->sessionService->getActiveSessions($request->user()->user_id);

        return response()->json(['success' => true, 'data' => ['sessions' => $sessions]]);
    }

    public function revokeSession(Request $request, int $sessionId): JsonResponse
    {
        $this->sessionService->revokeSession($sessionId);

        return response()->json(['success' => true, 'message' => trans('auth.session_revoked')]);
    }

    public function revokeAllSessions(Request $request): JsonResponse
    {
        $count = $this->sessionService->revokeAllSessions($request->user()->user_id);

        return response()->json(['success' => true, 'data' => ['revoked' => $count]]);
    }
}
