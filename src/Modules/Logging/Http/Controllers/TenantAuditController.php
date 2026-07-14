<?php

namespace MultiTenantSaas\Modules\Logging\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Logging\Models\AuditLog;

class TenantAuditController extends Controller
{
    use AuthorizesTenantAccess;

    public function index(Request $request)
    {
        $tenantId = TenantContext::getId();

        $query = AuditLog::where('tenant_id', $tenantId)->orderBy('created_at', 'desc');

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('resource_type')) {
            $query->where('resource_type', $request->resource_type);
        }

        $perPage = min((int) $request->get('per_page', 15), 100);
        $logs = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    public function show(Request $request, int $id)
    {
        $tenantId = TenantContext::getId();

        $log = AuditLog::where('tenant_id', $tenantId)->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $log,
        ]);
    }

    public function export(Request $request)
    {
        $tenantId = TenantContext::getId();

        $request->validate([
            'format' => 'nullable|in:csv,json',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $query = AuditLog::where('tenant_id', $tenantId)->orderBy('created_at', 'desc');

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('resource_type')) {
            $query->where('resource_type', $request->resource_type);
        }

        if ($request->filled('start_date')) {
            $query->where('created_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->where('created_at', '<=', $request->end_date . ' 23:59:59');
        }

        $format = $request->input('format', 'csv');
        $logs = $query->limit(10000)->get();

        if ($format === 'json') {
            return response()->json([
                'success' => true,
                'data' => $logs,
            ]);
        }

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="audit_logs_' . date('YmdHis') . '.csv"',
        ];

        $callback = function () use ($logs) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['ID', 'Tenant ID', 'User ID', 'Action', 'Resource Type', 'Resource ID', 'IP Address', 'Created At']);

            foreach ($logs as $log) {
                fputcsv($file, [
                    $log->log_id,
                    $log->tenant_id,
                    $log->user_id,
                    $log->action,
                    $log->resource_type,
                    $log->resource_id,
                    $log->ip_address,
                    $log->created_at,
                ]);
            }

            fclose($file);
        };

        return Response::stream($callback, 200, $headers);
    }
}
