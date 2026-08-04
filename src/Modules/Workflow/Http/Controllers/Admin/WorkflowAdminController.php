<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Workflow\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Workflow\Services\WorkflowService;

/**
 * 平台管理端：工作流定义管理
 */
class WorkflowAdminController extends Controller
{
    public function index()
    {
        $service = app(WorkflowService::class);

        return response()->json(['success' => true, 'data' => $service->listForTenant()]);
    }

    public function store(Request $request)
    {
        $service = app(WorkflowService::class);
        $request->validate(['name' => 'required|string', 'definition' => 'required|array']);
        $workflow = $service->create($request->all());

        return response()->json(['success' => true, 'data' => $workflow], 201);
    }

    public function update(Request $request, string $id)
    {
        $service = app(WorkflowService::class);
        $workflow = $service->update($id, $request->all());

        return response()->json(['success' => true, 'data' => $workflow]);
    }

    public function destroy(string $id)
    {
        $service = app(WorkflowService::class);
        $service->delete($id);

        return response()->json(['success' => true, 'message' => trans('workflow.deleted')]);
    }
}
