<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\DeveloperPortal\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\DeveloperPortal\Services\SandboxService;

/**
 * 平台管理端：开发者沙箱管理
 */
class DeveloperPortalAdminController extends Controller
{
    public function sandboxIndex()
    {
        $service = app(SandboxService::class);

        return response()->json(['success' => true, 'data' => $service->listSandboxes()]);
    }

    public function sandboxStore(Request $request)
    {
        $service = app(SandboxService::class);
        $developerId = $request->user()?->user_id ?? $request->user()?->id;

        return response()->json(['success' => true, 'data' => $service->createSandbox((int) $developerId)]);
    }
}
