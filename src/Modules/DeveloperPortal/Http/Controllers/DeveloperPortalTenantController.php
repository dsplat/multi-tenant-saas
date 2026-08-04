<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\DeveloperPortal\Http\Controllers;

use App\Http\Controllers\Controller;
use MultiTenantSaas\Modules\DeveloperPortal\Services\SandboxService;

/**
 * 租户端：开发者文档与沙箱
 */
class DeveloperPortalTenantController extends Controller
{
    public function docs()
    {
        return response()->json(['success' => true, 'data' => ['api_docs_url' => '/api/documentation']]);
    }

    public function sandbox()
    {
        $service = app(SandboxService::class);

        return response()->json(['success' => true, 'data' => $service->getTenantSandbox()]);
    }
}
