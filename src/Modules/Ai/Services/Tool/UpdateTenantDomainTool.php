<?php

namespace MultiTenantSaas\Modules\Ai\Services\Tool;

use Illuminate\Validation\ValidationException;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Auth\Services\RbacService;
use MultiTenantSaas\Modules\Domain\Services\DomainService;

/**
 * update_tenant_domain — 小助手直接绑定当前租户的自定义域名
 *
 * 与「租户设置 → 域名设置」页走同一个 DomainService::updateDomain
 * （格式校验、保留域名黑名单、唯一性、状态置 pending、生成归属验证 token）。
 *
 * 注册为 L2：系统会先下发确认卡片，用户确认后才真正执行。
 * 绑定后仍需：域名归属文件验证 + ICP 备案核验 + 平台审核，工具返回结果中附带后续步骤指引。
 */
class UpdateTenantDomainTool implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        if (! app(RbacService::class)->check('tenant.update')) {
            return ['error' => true, 'message' => '当前账号没有租户设置修改权限（tenant.update）'];
        }

        $domain = mb_strtolower(trim((string) ($arguments['domain'] ?? '')));
        if ($domain === '') {
            return ['error' => true, 'message' => '未提供要绑定的自定义域名（domain）'];
        }

        $service = new DomainService;

        try {
            $service->updateDomain($tenantId, $domain);
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->implode('；');

            return ['error' => true, 'message' => '域名绑定失败：' . $messages];
        } catch (ServiceUnavailableException $e) {
            return ['error' => true, 'message' => '域名绑定失败：' . $e->getMessage()];
        }

        // 附带后续步骤指引，便于小助手向用户转述
        $instructions = $service->getVerificationInstructions($tenantId);
        $cnameTarget = config('domain.wildcard_base') ? 'app.' . config('domain.wildcard_base') : null;

        return [
            'success' => true,
            'domain' => $domain,
            'status' => 'pending',
            'verify_file_path' => $instructions['file_path'],
            'verify_file_content' => $instructions['file_content'],
            'cname_target' => $cnameTarget,
            'message' => "自定义域名 {$domain} 已提交绑定（待审核）。后续步骤：1) 将域名解析 CNAME 到 " . ($cnameTarget ?? '平台入口域名') . "；2) 在该域名下放置验证文件 {$instructions['file_path']}（内容为验证 token）完成归属验证；3) 域名须已完成 ICP 备案；4) 平台审核通过后生效。",
        ];
    }
}
