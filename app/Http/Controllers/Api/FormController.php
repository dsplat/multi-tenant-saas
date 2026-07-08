<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Models\Form;
use MultiTenantSaas\Models\FormSubmission;
use MultiTenantSaas\Services\FormBuilderService;

class FormController extends Controller
{
    use AuthorizesTenantAccess;

    public function __construct(
        private FormBuilderService $formService,
    ) {}

    // ========== 表单管理 ==========

    public function index(Request $request, int $tenantId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $filters = array_filter([
            'status' => $request->query('status'),
            'keyword' => $request->query('keyword'),
        ]);

        $forms = $this->formService->getForms($tenantId, $filters, $request->query('per_page'));

        return response()->json(['success' => true, 'data' => $forms]);
    }

    public function store(Request $request, int $tenantId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:draft,published,closed'],
            'submit_limit' => ['nullable', 'integer', 'min:0'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'submit_text' => ['nullable', 'string', 'max:32'],
            'success_message' => ['nullable', 'string', 'max:255'],
            'is_public' => ['nullable', 'boolean'],
            'require_login' => ['nullable', 'boolean'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*.field_key' => ['required', 'string', 'max:64'],
            'fields.*.field_type' => ['required', 'string', 'in:text,textarea,number,email,phone,date,time,datetime,select,multi_select,radio,checkbox,file,image,rich_text,rating,signature,location,cascader'],
            'fields.*.label' => ['required', 'string', 'max:128'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:255'],
            'fields.*.default_value' => ['nullable'],
            'fields.*.options' => ['nullable', 'array'],
            'fields.*.is_required' => ['nullable', 'boolean'],
            'fields.*.sort_order' => ['nullable', 'integer'],
            'fields.*.validation_rules' => ['nullable', 'array'],
        ]);

        $form = $this->formService->createForm($data, $tenantId);

        return response()->json([
            'success' => true,
            'data' => $form->load('fields'),
        ], 201);
    }

    public function show(Request $request, int $tenantId, int $formId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $form = Form::where('form_id', $formId)
            ->where('tenant_id', $tenantId)
            ->with('fields')
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $form]);
    }

    public function update(Request $request, int $tenantId, int $formId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $form = Form::where('form_id', $formId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:draft,published,closed'],
            'submit_limit' => ['nullable', 'integer', 'min:0'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date'],
            'submit_text' => ['nullable', 'string', 'max:32'],
            'success_message' => ['nullable', 'string', 'max:255'],
            'is_public' => ['nullable', 'boolean'],
            'require_login' => ['nullable', 'boolean'],
            'fields' => ['sometimes', 'array', 'min:1'],
            'fields.*.field_key' => ['required_with:fields', 'string', 'max:64'],
            'fields.*.field_type' => ['required_with:fields', 'string'],
            'fields.*.label' => ['required_with:fields', 'string', 'max:128'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:255'],
            'fields.*.default_value' => ['nullable'],
            'fields.*.options' => ['nullable', 'array'],
            'fields.*.is_required' => ['nullable', 'boolean'],
            'fields.*.sort_order' => ['nullable', 'integer'],
            'fields.*.validation_rules' => ['nullable', 'array'],
        ]);

        $form = $this->formService->updateForm($form, $data);

        return response()->json(['success' => true, 'data' => $form]);
    }

    public function destroy(Request $request, int $tenantId, int $formId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        $form = Form::where('form_id', $formId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if ($form->status === 'published') {
            return response()->json([
                'success' => false,
                'message' => '无法删除已发布的表单，请先关闭',
            ], 422);
        }

        $form->delete();

        return response()->json(['success' => true, 'message' => trans('common.deleted')]);
    }

    // ========== 表单提交（公开接口） ==========

    public function submit(Request $request, int $formId): JsonResponse
    {
        $data = $request->validate([
            'data' => ['required', 'array'],
        ]);

        try {
            $submission = $this->formService->submitForm(
                $formId,
                $data['data'],
                $request->user()?->user_id,
            );

            return response()->json([
                'success' => true,
                'data' => $submission,
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    // ========== 提交记录管理 ==========

    public function submissions(Request $request, int $tenantId, int $formId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保表单属于当前租户
        Form::where('form_id', $formId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $filters = array_filter([
            'start_date' => $request->query('start_date'),
            'end_date' => $request->query('end_date'),
        ]);

        $submissions = $this->formService->getSubmissions($formId, $filters, $request->query('per_page'));

        return response()->json(['success' => true, 'data' => $submissions]);
    }

    // ========== 统计与导出 ==========

    public function statistics(Request $request, int $tenantId, int $formId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保表单属于当前租户
        Form::where('form_id', $formId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $stats = $this->formService->getStatistics($formId);

        return response()->json(['success' => true, 'data' => $stats]);
    }

    public function export(Request $request, int $tenantId, int $formId): JsonResponse
    {
        $this->ensureTenantAccess($request, $tenantId);

        // 确保表单属于当前租户
        Form::where('form_id', $formId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $format = $request->query('format', 'csv');
        $data = $this->formService->exportData($formId, $format);

        return response()->json(['success' => true, 'data' => $data]);
    }
}
