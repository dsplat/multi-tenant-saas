<?php

namespace MultiTenantSaas\Modules\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use MultiTenantSaas\Modules\Infrastructure\Models\SystemSetting;

/**
 * 申请字段配置控制器
 *
 * 管理租户申请表单的字段配置（哪些字段显示/必填/可选）。
 * 使用 SystemSetting 存储，group = 'apply_fields'。
 */
class ApplyFieldConfigController extends Controller
{
    /**
     * 默认字段配置。
     */
    protected const DEFAULT_FIELDS = [
        [
            'name' => 'org_name',
            'label' => '组织名称',
            'type' => 'text',
            'required' => true,
            'enabled' => true,
            'sort' => 1,
        ],
        [
            'name' => 'org_industry',
            'label' => '所属行业',
            'type' => 'select',
            'required' => false,
            'enabled' => true,
            'sort' => 2,
            'options' => [
                '互联网/IT', '金融', '教育', '医疗健康', '制造业',
                '零售/电商', '房地产', '媒体/传播', '其他',
            ],
        ],
        [
            'name' => 'org_size',
            'label' => '组织规模',
            'type' => 'select',
            'required' => false,
            'enabled' => true,
            'sort' => 3,
            'options' => ['1-10人', '11-50人', '51-200人', '201-500人', '500人以上'],
        ],
        [
            'name' => 'contact_name',
            'label' => '联系人姓名',
            'type' => 'text',
            'required' => true,
            'enabled' => true,
            'sort' => 4,
        ],
        [
            'name' => 'contact_phone',
            'label' => '联系电话',
            'type' => 'tel',
            'required' => false,
            'enabled' => true,
            'sort' => 5,
        ],
        [
            'name' => 'description',
            'label' => '申请说明',
            'type' => 'textarea',
            'required' => false,
            'enabled' => false,
            'sort' => 6,
        ],
    ];

    /**
     * 获取申请字段配置。
     *
     * GET /admin/apply-fields
     * GET /public/apply-fields（公开，供申请表单渲染）
     */
    public function index(): JsonResponse
    {
        $setting = SystemSetting::where('group', 'apply_fields')
            ->where('key', 'fields')
            ->first();

        $fields = $setting ? json_decode($setting->value, true) : self::DEFAULT_FIELDS;

        return response()->json([
            'success' => true,
            'data' => ['fields' => $fields],
        ]);
    }

    /**
     * 更新申请字段配置。
     *
     * PUT /admin/apply-fields
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'fields' => 'required|array',
            'fields.*.name' => 'required|string|max:50',
            'fields.*.label' => 'required|string|max:100',
            'fields.*.type' => 'required|string|in:text,textarea,select,tel,email,number',
            'fields.*.required' => 'required|boolean',
            'fields.*.enabled' => 'required|boolean',
            'fields.*.sort' => 'nullable|integer',
            'fields.*.options' => 'nullable|array',
        ]);

        SystemSetting::updateOrCreate(
            ['group' => 'apply_fields', 'key' => 'fields'],
            ['value' => json_encode($request->input('fields'), JSON_UNESCAPED_UNICODE)]
        );

        return response()->json([
            'success' => true,
            'message' => trans('common.updated'),
            'data' => ['fields' => $request->input('fields')],
        ]);
    }
}
