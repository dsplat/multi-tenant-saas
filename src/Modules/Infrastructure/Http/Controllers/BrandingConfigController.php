<?php

namespace MultiTenantSaas\Modules\Infrastructure\Http\Controllers;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Services\BrandingService;
use MultiTenantSaas\Modules\Logging\Services\AuditService;

class BrandingConfigController extends Controller
{
    use ApiResponse, AuthorizesTenantAccess;

    public function __construct(
        protected BrandingService $brandingService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId !== null ? (int) $tenantId : null);

        $config = $this->brandingService->getConfig((int) $tenantId);

        return $this->successResponse($config);
    }

    public function update(Request $request): JsonResponse
    {
        $tenantId = TenantContext::getId();
        $this->ensureTenantAccess($request, $tenantId !== null ? (int) $tenantId : null);

        $validated = $request->validate([
            'logo_url' => 'nullable|string|max:2048',
            'favicon_url' => 'nullable|string|max:2048',
            'primary_color' => 'nullable|string|max:20',
            'secondary_color' => 'nullable|string|max:20',
            'custom_css' => 'nullable|string|max:65535',
            'custom_domain' => 'nullable|string|max:200',
            'login_page_style' => 'nullable|string|max:50',
            'email_template' => 'nullable|string|max:50',
        ]);

        $config = $this->brandingService->updateConfig((int) $tenantId, $validated);

        app(AuditService::class)->log('update', 'branding_config', $config->branding_config_id, null, $validated);

        return $this->successResponse($config);
    }
}
