<?php

declare(strict_types=1);

namespace MultiTenantSaas\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * AI Capability — stub controller
 *
 * 路由已在 src/Routes/ai.php 和 src/Modules/Ai/Routes/api.php 中注册，
 * 返回空数据 stub，后续填充业务逻辑。
 */
class CapabilityController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => []]);
    }

    public function execute(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'capability' => 'required|string|max:100',
            'params' => 'nullable|array',
        ]);

        return response()->json(['success' => true, 'data' => ['result' => null]]);
    }

    public function batch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tasks' => 'required|array|min:1',
            'tasks.*.capability' => 'required|string|max:100',
            'tasks.*.params' => 'nullable|array',
        ]);

        return response()->json(['success' => true, 'data' => []]);
    }
}
