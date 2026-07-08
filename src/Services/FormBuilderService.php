<?php

namespace MultiTenantSaas\Services;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Models\Form;
use MultiTenantSaas\Models\FormField;
use MultiTenantSaas\Models\FormSubmission;

/**
 * 表单构建器服务
 *
 * 通用表单构建 + 数据收集引擎。
 * 支持拖拽式表单设计、多种字段类型、数据校验、提交管理。
 *
 * 字段类型: text, textarea, number, email, phone, date, time, datetime,
 *          select, multi_select, radio, checkbox, file, image, rich_text,
 *          rating, signature, location, cascader
 *
 * 特性:
 * - 表单模板 CRUD
 * - 字段拖拽排序
 * - 提交数据校验
 * - 数据导出
 * - 租户隔离
 */
class FormBuilderService
{
    /**
     * 创建表单
     */
    public function createForm(array $data, int $tenantId): Form
    {
        return DB::transaction(function () use ($data, $tenantId) {
            $form = Form::create([
                'tenant_id' => $tenantId,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'submit_limit' => $data['submit_limit'] ?? 0,
                'start_at' => $data['start_at'] ?? null,
                'end_at' => $data['end_at'] ?? null,
                'submit_text' => $data['submit_text'] ?? '提交',
                'success_message' => $data['success_message'] ?? '提交成功',
                'is_public' => $data['is_public'] ?? false,
                'require_login' => $data['require_login'] ?? false,
                'metadata' => $data['metadata'] ?? null,
            ]);

            if (!empty($data['fields'])) {
                $this->saveFields($form->getKey(), $data['fields']);
            }

            return $form;
        });
    }

    /**
     * 更新表单
     */
    public function updateForm(Form $form, array $data): Form
    {
        return DB::transaction(function () use ($form, $data) {
            $form->update([
                'title' => $data['title'] ?? $form->title,
                'description' => $data['description'] ?? $form->description,
                'status' => $data['status'] ?? $form->status,
                'submit_limit' => $data['submit_limit'] ?? $form->submit_limit,
                'start_at' => $data['start_at'] ?? $form->start_at,
                'end_at' => $data['end_at'] ?? $form->end_at,
                'submit_text' => $data['submit_text'] ?? $form->submit_text,
                'success_message' => $data['success_message'] ?? $form->success_message,
                'is_public' => $data['is_public'] ?? $form->is_public,
                'require_login' => $data['require_login'] ?? $form->require_login,
                'metadata' => $data['metadata'] ?? $form->metadata,
            ]);

            if (isset($data['fields'])) {
                FormField::where('form_id', $form->getKey())->delete();
                $this->saveFields($form->getKey(), $data['fields']);
            }

            return $form->fresh(['fields']);
        });
    }

    /**
     * 提交表单数据
     */
    public function submitForm(int $formId, array $formData, ?int $userId = null, ?int $tenantId = null): FormSubmission
    {
        $form = Form::findOrFail($formId);

        if ($form->status !== 'published') {
            throw new \RuntimeException(trans('form.form_not_published'));
        }

        if ($form->start_at && Carbon::parse($form->start_at)->isFuture()) {
            throw new \RuntimeException(trans('form.form_not_started'));
        }

        if ($form->end_at && Carbon::parse($form->end_at)->isPast()) {
            throw new \RuntimeException(trans('form.form_ended'));
        }

        if ($form->submit_limit > 0) {
            $count = FormSubmission::where('form_id', $formId)->count();
            if ($count >= $form->submit_limit) {
                throw new \RuntimeException(trans('form.form_submit_limit'));
            }
        }

        $validated = $this->validateSubmission($form, $formData);

        return FormSubmission::create([
            'form_id' => $formId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'data' => $validated,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * 校验提交数据
     */
    protected function validateSubmission(Form $form, array $formData): array
    {
        $fields = $form->fields;
        $validated = [];

        foreach ($fields as $field) {
            $value = $formData[$field->field_key] ?? null;

            if ($field->is_required && empty($value) && $value !== '0' && $value !== 0) {
                throw new \RuntimeException(trans('form.field_required', ['field' => $field->label]));
            }

            if ($value !== null && $value !== '') {
                $value = $this->validateFieldValue($field, $value);
            }

            $validated[$field->field_key] = $value;
        }

        return $validated;
    }

    /**
     * 校验单个字段值
     */
    protected function validateFieldValue(FormField $field, mixed $value): mixed
    {
        return match ($field->field_type) {
            'number' => is_numeric($value) ? (float) $value : throw new \RuntimeException("{$field->label} 必须是数字"),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : throw new \RuntimeException("{$field->label} 格式不正确"),
            'phone' => preg_match('/^1[3-9]\d{9}$/', $value) ? $value : throw new \RuntimeException("{$field->label} 格式不正确"),
            'select', 'radio' => in_array($value, $field->options ?? []) ? $value : throw new \RuntimeException("{$field->label} 选项无效"),
            'multi_select', 'checkbox' => is_array($value) ? $value : [$value],
            'date' => Carbon::parse($value)->toDateString(),
            'datetime' => Carbon::parse($value)->toDateTimeString(),
            'time' => preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value) ? $value : throw new \RuntimeException("{$field->label} 时间格式不正确"),
            'rating' => max(1, min(5, (int) $value)),
            'signature' => $this->validateSignature($field, $value),
            'location' => $this->validateLocation($field, $value),
            'cascader' => $this->validateCascader($field, $value),
            default => (string) $value,
        };
    }

    /**
     * 校验签名字段
     * 签名值应为 base64 编码的图片数据
     */
    protected function validateSignature(FormField $field, mixed $value): string
    {
        if (!is_string($value)) {
            throw new \RuntimeException("{$field->label} 签名数据格式不正确");
        }

        // 验证 base64 格式（支持 data:image/png;base64,... 或纯 base64）
        if (!preg_match('/^(data:image\/(png|jpeg|svg\+xml);base64,)?[A-Za-z0-9+\/=]+$/', $value)) {
            throw new \RuntimeException("{$field->label} 签名数据格式不正确");
        }

        return $value;
    }

    /**
     * 校验位置字段
     * 位置值应为 {lat, lng, address} 结构
     */
    protected function validateLocation(FormField $field, mixed $value): array
    {
        if (!is_array($value)) {
            throw new \RuntimeException("{$field->label} 位置数据格式不正确");
        }

        if (!isset($value['lat']) || !isset($value['lng'])) {
            throw new \RuntimeException("{$field->label} 缺少经纬度信息");
        }

        if (!is_numeric($value['lat']) || !is_numeric($value['lng'])) {
            throw new \RuntimeException("{$field->label} 经纬度必须是数字");
        }

        $lat = (float) $value['lat'];
        $lng = (float) $value['lng'];

        if ($lat < -90 || $lat > 90) {
            throw new \RuntimeException("{$field->label} 纬度范围不正确");
        }

        if ($lng < -180 || $lng > 180) {
            throw new \RuntimeException("{$field->label} 经度范围不正确");
        }

        return [
            'lat' => $lat,
            'lng' => $lng,
            'address' => $value['address'] ?? null,
            'name' => $value['name'] ?? null,
        ];
    }

    /**
     * 校验级联选择字段
     * 级联值应为数组（路径选择）
     */
    protected function validateCascader(FormField $field, mixed $value): array
    {
        if (!is_array($value)) {
            throw new \RuntimeException("{$field->label} 级联选择数据格式不正确");
        }

        // 验证每一级的值
        $options = $field->options ?? [];
        $currentOptions = $options;
        $validated = [];

        foreach ($value as $level => $item) {
            if (!is_string($item) && !is_numeric($item)) {
                throw new \RuntimeException("{$field->label} 第 " . ($level + 1) . " 级选择值格式不正确");
            }

            // 查找当前级别是否有该选项
            $found = false;
            foreach ($currentOptions as $option) {
                $optionValue = $option['value'] ?? $option;
                if ((string) $optionValue === (string) $item) {
                    $validated[] = $item;
                    $currentOptions = $option['children'] ?? [];
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                throw new \RuntimeException("{$field->label} 第 " . ($level + 1) . " 级选择值无效");
            }
        }

        return $validated;
    }

    /**
     * 保存表单字段
     */
    protected function saveFields(int $formId, array $fields): void
    {
        foreach ($fields as $index => $field) {
            FormField::create([
                'form_id' => $formId,
                'field_key' => $field['field_key'],
                'field_type' => $field['field_type'] ?? 'text',
                'label' => $field['label'],
                'placeholder' => $field['placeholder'] ?? null,
                'default_value' => $field['default_value'] ?? null,
                'options' => $field['options'] ?? null,
                'is_required' => $field['is_required'] ?? false,
                'sort_order' => $field['sort_order'] ?? $index,
                'validation_rules' => $field['validation_rules'] ?? null,
                'metadata' => $field['metadata'] ?? null,
            ]);
        }
    }

    /**
     * 查询表单列表
     */
    public function getForms(int $tenantId, array $filters = [], ?int $perPage = null): Collection|LengthAwarePaginator
    {
        $query = Form::where('tenant_id', $tenantId)
            ->withCount(['fields', 'submissions']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['keyword'])) {
            $query->where('title', 'like', "%{$filters['keyword']}%");
        }

        $query->orderByDesc('created_at');

        return $perPage !== null ? $query->paginate($perPage) : $query->get();
    }

    /**
     * 查询提交记录
     */
    public function getSubmissions(int $formId, array $filters = [], ?int $perPage = null): Collection|LengthAwarePaginator
    {
        $query = FormSubmission::where('form_id', $formId)->with('form.fields');

        if (!empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->where('created_at', '<=', $filters['end_date']);
        }

        $query->orderByDesc('created_at');

        return $perPage !== null ? $query->paginate($perPage) : $query->get();
    }

    /**
     * 获取提交统计
     */
    public function getStatistics(int $formId): array
    {
        $form = Form::with('fields')->findOrFail($formId);

        $totalSubmissions = FormSubmission::where('form_id', $formId)->count();
        $todaySubmissions = FormSubmission::where('form_id', $formId)
            ->whereDate('created_at', today())
            ->count();

        $dailyStats = FormSubmission::where('form_id', $formId)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'form' => $form->toArray(),
            'total_submissions' => $totalSubmissions,
            'today_submissions' => $todaySubmissions,
            'daily_stats' => $dailyStats->toArray(),
        ];
    }

    /**
     * 导出数据
     */
    public function exportData(int $formId, string $format = 'csv'): array
    {
        $form = Form::with('fields')->findOrFail($formId);
        $submissions = FormSubmission::where('form_id', $formId)->orderBy('created_at')->get();

        $headers = $form->fields->pluck('label', 'field_key')->toArray();
        $rows = [];

        foreach ($submissions as $submission) {
            $row = [];
            foreach ($form->fields as $field) {
                $value = $submission->data[$field->field_key] ?? '';
                $row[$field->field_key] = is_array($value) ? implode(', ', $value) : $value;
            }
            $row['submitted_at'] = $submission->created_at->toDateTimeString();
            $rows[] = $row;
        }

        return [
            'form_title' => $form->title,
            'headers' => $headers,
            'rows' => $rows,
            'total' => count($rows),
        ];
    }
}