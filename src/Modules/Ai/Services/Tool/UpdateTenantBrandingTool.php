<?php

namespace MultiTenantSaas\Modules\Ai\Services\Tool;

use Illuminate\Support\Facades\Validator;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Auth\Services\RbacService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Logging\Services\AuditService;

/**
 * update_tenant_branding — 小助手直接更新当前租户品牌设置
 *
 * 与「租户设置 → 品牌设置」页走同一套字段与校验（UpdateTenantRequest 白名单），
 * 避免小助手只能生成表单填充建议、让用户手动保存。
 *
 * 注册为 L2：系统会先下发确认卡片（展示将写入的字段与值），
 * 用户确认后才真正执行，满足「先跟用户确认要设置的信息和操作」。
 */
class UpdateTenantBrandingTool implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        if (! app(RbacService::class)->check('tenant.update')) {
            return ['error' => true, 'message' => '当前账号没有租户设置修改权限（tenant.update）'];
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return ['error' => true, 'message' => '租户不存在'];
        }

        // 与 UpdateTenantRequest 一致的字段白名单与校验规则
        $validated = Validator::make($arguments, [
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'logo' => 'nullable|string|max:500',
            'primary_color' => 'nullable|string|max:20',
            'secondary_color' => 'nullable|string|max:20',
            'login_page_message' => 'nullable|string|max:500',
        ])->validate();

        // 至少需要一个有效字段（空字符串视为未提供）
        $provided = array_filter($validated, fn ($v) => $v !== null && $v !== '');
        if (empty($provided)) {
            return ['error' => true, 'message' => '未提供任何要设置的字段（支持 name/description/logo/primary_color/secondary_color/login_page_message）'];
        }

        $oldValues = $tenant->only(['name', 'description', 'logo', 'branding']);

        if (isset($provided['name'])) {
            $tenant->name = $provided['name'];
        }
        if (array_key_exists('description', $provided)) {
            $tenant->description = $provided['description'];
        }
        if (array_key_exists('logo', $provided)) {
            $tenant->logo = $provided['logo'];
        }

        $brandingKeys = ['primary_color', 'secondary_color', 'login_page_message'];
        $brandingChanged = array_intersect_key($provided, array_flip($brandingKeys));
        if (! empty($brandingChanged)) {
            $branding = $tenant->branding ?? [];
            foreach ($brandingKeys as $key) {
                if (array_key_exists($key, $provided)) {
                    $branding[$key] = $provided[$key];
                }
            }
            $tenant->branding = $branding;
        }

        $tenant->save();

        app(AuditService::class)->log('update', 'tenant', $tenantId, $oldValues, $provided);

        return [
            'success' => true,
            'tenant_id' => (string) $tenant->tenant_id,
            'updated' => $provided,
            'message' => '品牌设置已更新：' . implode('、', array_keys($provided)),
        ];
    }
}
