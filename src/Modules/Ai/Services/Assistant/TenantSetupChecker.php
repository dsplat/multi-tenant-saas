<?php

namespace MultiTenantSaas\Modules\Ai\Services\Assistant;

use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantSetting;
use MultiTenantSaas\Modules\Knowledge\Models\ExternalKbConnection;
use MultiTenantSaas\Modules\Operator\Models\OperatorTenant;

/**
 * 租户设置完善度检查器
 *
 * 供 AI 小助手开场引导消费（GET /v1/ai/assistant/suggestions 的
 * setup_checklist 块，仅 tenant_admin 可见）：检查关键配置项是否
 * 就绪，未完成项附带控制台路由供前端「去完成」跳转。
 *
 * 下游项目（如 SCRM 的渠道配置检查）通过
 * config('ai.secretary.extra_setup_checkers') 注册扩展检查类，
 * 类需提供 checks(int $tenantId): array，返回条目结构与本类一致。
 */
class TenantSetupChecker
{
    /**
     * 生成完善度清单。
     *
     * 条目可选携带 prompt：点击引导项时唤醒小助手并预填的提示词
     * （供 console Dashboard 引导卡片消费；开场引导可忽略）。
     *
     * @return array{items: list<array{key: string, label: string, done: bool, route: ?string, description: string, prompt: ?string}>, completed: int, total: int}
     */
    public function checklist(int $tenantId): array
    {
        $items = array_merge($this->frameworkChecks($tenantId), $this->extraChecks($tenantId));

        $completed = count(array_filter($items, static fn (array $item): bool => (bool) $item['done']));

        return [
            'items' => $items,
            'completed' => $completed,
            'total' => count($items),
        ];
    }

    /**
     * 框架内置检查项。
     *
     * @return list<array{key: string, label: string, done: bool, route: ?string, description: string, prompt: ?string}>
     */
    private function frameworkChecks(int $tenantId): array
    {
        return [
            [
                'key' => 'wechat_login',
                'label' => '配置微信登录',
                'done' => $this->hasWechatLogin($tenantId),
                'route' => '/oauth',
                'description' => '配置微信扫码或企业微信登录后，成员可免密进入控制台。',
                'prompt' => '帮我配置微信登录',
            ],
            [
                'key' => 'staff_invited',
                'label' => '邀请团队成员',
                'done' => $this->hasInvitedStaff($tenantId),
                'route' => '/members',
                'description' => '邀请同事加入团队，协同使用数字员工。',
                'prompt' => '帮我邀请团队成员',
            ],
            [
                'key' => 'knowledge_base',
                'label' => '接入知识库',
                'done' => $this->hasActiveKb($tenantId),
                'route' => '/external-kb',
                'description' => '接入外部知识库后，AI 回答将基于你的业务资料。',
                'prompt' => '帮我接入知识库',
            ],
            [
                'key' => 'agents_enabled',
                'label' => '启用数字员工',
                'done' => $this->hasEnabledAgents($tenantId),
                'route' => '/agents',
                'description' => '启用销售、客服、营销等数字员工，让 AI 替你干活。',
                'prompt' => '帮我启用数字员工',
            ],
        ];
    }

    /**
     * 下游扩展检查项（config ai.secretary.extra_setup_checkers）。
     *
     * @return list<array{key: string, label: string, done: bool, route: ?string, description: string, prompt: ?string}>
     */
    private function extraChecks(int $tenantId): array
    {
        $items = [];

        foreach ((array) config('ai.secretary.extra_setup_checkers', []) as $class) {
            if (! is_string($class) || ! class_exists($class) || ! method_exists($class, 'checks')) {
                continue;
            }

            foreach ((array) app($class)->checks($tenantId) as $item) {
                if (is_array($item) && isset($item['key'], $item['label'], $item['done'])) {
                    $items[] = [
                        'key' => (string) $item['key'],
                        'label' => (string) $item['label'],
                        'done' => (bool) $item['done'],
                        'route' => $item['route'] ?? null,
                        'description' => (string) ($item['description'] ?? ''),
                        'prompt' => isset($item['prompt']) ? (string) $item['prompt'] : null,
                    ];
                }
            }
        }

        return $items;
    }

    /**
     * 微信登录：微信扫码或企业微信任一已配置即算完成。
     */
    private function hasWechatLogin(int $tenantId): bool
    {
        $oauth = TenantSetting::getGroup($tenantId, 'oauth');

        return ! empty($oauth['wechat_client_id']) || ! empty($oauth['wechat_work_corp_id']);
    }

    /**
     * 员工邀请：除创建者外还有其他活跃成员。
     */
    private function hasInvitedStaff(int $tenantId): bool
    {
        return OperatorTenant::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count() > 1;
    }

    /**
     * 知识库：存在活跃的外部知识库连接。
     */
    private function hasActiveKb(int $tenantId): bool
    {
        return ExternalKbConnection::where('tenant_id', $tenantId)
            ->active()
            ->exists();
    }

    /**
     * 数字员工：小秘书之外至少启用一名。
     */
    private function hasEnabledAgents(int $tenantId): bool
    {
        return Agent::where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->where('role', '!=', 'system_secretary')
            ->exists();
    }
}
