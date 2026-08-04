<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Workflow\Services\WorkflowService;

/**
 * 租户端：工作流查看与执行
 */
class WorkflowTenantController extends Controller
{
    public function index()
    {
        $service = app(WorkflowService::class);

        return response()->json(['success' => true, 'data' => $service->listForTenant()]);
    }

    public function show(string $id)
    {
        $service = app(WorkflowService::class);

        return response()->json(['success' => true, 'data' => $service->find($id)]);
    }

    public function execute(Request $request, string $id)
    {
        $service = app(WorkflowService::class);
        $result = $service->startExecution($id, $request->all());

        return response()->json(['success' => true, 'data' => $result]);
    }
}
