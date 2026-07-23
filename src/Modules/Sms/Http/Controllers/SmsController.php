<?php

namespace MultiTenantSaas\Modules\Sms\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Modules\Sms\Models\SmsBatchTask;
use MultiTenantSaas\Modules\Sms\Models\SmsTemplate;
use MultiTenantSaas\Modules\Sms\Services\SmsService;

class SmsController extends Controller
{
    use AuthorizesTenantAccess;

    // ========== 模板管理 ==========

    public function indexTemplates(Request $request, int $tenantId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $filters = array_filter([
            'channel' => $request->query('channel'),
            'status' => $request->query('status'),
        ]);

        $templates = app(SmsService::class)->getTemplates($filters);

        return response()->json(['success' => true, 'data' => $templates]);
    }

    public function storeTemplate(Request $request, int $tenantId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'content' => ['required', 'string'],
            'variables' => ['nullable', 'array'],
            'channel' => ['required', 'string', 'in:' . implode(',', SmsTemplate::CHANNELS)],
            'provider_template_id' => ['nullable', 'string', 'max:128'],
        ]);

        $data['tenant_id'] = $tenantId;
        $data['status'] = $data['status'] ?? SmsTemplate::STATUS_PENDING_APPROVAL;

        $template = app(SmsService::class)->createTemplate($data);

        return response()->json(['success' => true, 'data' => $template], 201);
    }

    public function showTemplate(Request $request, int $tenantId, int $templateId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $template = SmsTemplate::where('sms_template_id', $templateId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $template]);
    }

    public function updateTemplate(Request $request, int $tenantId, int $templateId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保模板属于当前租户
        SmsTemplate::where('sms_template_id', $templateId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:128'],
            'content' => ['sometimes', 'string'],
            'variables' => ['nullable', 'array'],
            'channel' => ['sometimes', 'string', 'in:' . implode(',', SmsTemplate::CHANNELS)],
            'provider_template_id' => ['nullable', 'string', 'max:128'],
        ]);

        $template = app(SmsService::class)->updateTemplate($templateId, $data);

        return response()->json(['success' => true, 'data' => $template]);
    }

    public function destroyTemplate(Request $request, int $tenantId, int $templateId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $template = SmsTemplate::where('sms_template_id', $templateId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $template->delete();

        return response()->json(['success' => true, 'message' => trans('common.deleted')]);
    }

    public function submitForApproval(Request $request, int $tenantId, int $templateId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保模板属于当前租户
        SmsTemplate::where('sms_template_id', $templateId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $template = app(SmsService::class)->submitForApproval($templateId);

        return response()->json(['success' => true, 'data' => $template]);
    }

    public function renderContent(Request $request, int $tenantId, int $templateId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保模板属于当前租户
        SmsTemplate::where('sms_template_id', $templateId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $variables = $request->input('variables', []);
        $content = app(SmsService::class)->renderContent($templateId, $variables);

        return response()->json(['success' => true, 'data' => ['content' => $content]]);
    }

    // ========== 批量发送 ==========

    public function batchSend(Request $request, int $tenantId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $data = $request->validate([
            'template_id' => ['required', 'integer'],
            'phones' => ['required', 'array', 'min:1'],
            'phones.*' => ['string', 'regex:/^1[3-9]\d{9}$/'],
        ]);

        // 确保模板属于当前租户
        SmsTemplate::where('sms_template_id', $data['template_id'])
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $task = app(SmsService::class)->batchSend($data['template_id'], $data['phones']);

        return response()->json(['success' => true, 'data' => $task], 201);
    }

    public function scheduledSend(Request $request, int $tenantId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $data = $request->validate([
            'template_id' => ['required', 'integer'],
            'phones' => ['required', 'array', 'min:1'],
            'phones.*' => ['string', 'regex:/^1[3-9]\d{9}$/'],
            'scheduled_at' => ['required', 'date', 'after:now'],
        ]);

        // 确保模板属于当前租户
        SmsTemplate::where('sms_template_id', $data['template_id'])
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $task = app(SmsService::class)->scheduledSend($data['template_id'], $data['phones'], $data['scheduled_at']);

        return response()->json(['success' => true, 'data' => $task], 201);
    }

    public function showBatchTask(Request $request, int $tenantId, int $taskId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $task = SmsBatchTask::where('batch_task_id', $taskId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $task]);
    }

    public function cancelBatchTask(Request $request, int $tenantId, int $taskId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保任务属于当前租户
        SmsBatchTask::where('batch_task_id', $taskId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $task = app(SmsService::class)->cancelBatchTask($taskId);

        return response()->json(['success' => true, 'data' => $task]);
    }

    // ========== 到达率统计 ==========

    public function deliveryStats(Request $request, int $tenantId, int $taskId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保任务属于当前租户
        SmsBatchTask::where('batch_task_id', $taskId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $stats = app(SmsService::class)->getDeliveryStats($taskId);

        return response()->json(['success' => true, 'data' => $stats]);
    }

    public function overallStats(Request $request, int $tenantId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $from = $request->query('from');
        $to = $request->query('to');

        $stats = app(SmsService::class)->getOverallStats($tenantId, $from, $to);

        return response()->json(['success' => true, 'data' => $stats]);
    }

    // ========== 退订管理 ==========

    public function indexUnsubscribes(Request $request, int $tenantId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $unsubscribes = app(SmsService::class)->getUnsubscribes($tenantId);

        return response()->json(['success' => true, 'data' => $unsubscribes]);
    }

    public function storeUnsubscribe(Request $request, int $tenantId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^1[3-9]\d{9}$/'],
            'reason' => ['nullable', 'string', 'max:512'],
        ]);

        $unsubscribe = app(SmsService::class)->unsubscribe(
            $data['phone'],
            $tenantId,
            $request->user()->id,
            $data['reason'] ?? null
        );

        return response()->json(['success' => true, 'data' => $unsubscribe], 201);
    }

    public function checkUnsubscribed(Request $request, int $tenantId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $request->validate([
            'phone' => ['required', 'string', 'regex:/^1[3-9]\d{9}$/'],
        ]);

        $isUnsubscribed = app(SmsService::class)->isUnsubscribed($request->phone, $tenantId);

        return response()->json(['success' => true, 'data' => ['is_unsubscribed' => $isUnsubscribed]]);
    }
}
