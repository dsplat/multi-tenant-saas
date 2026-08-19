<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Agent;

/**
 * 工具可读标签映射（服务端）
 *
 * 与前端 toolLabels.ts 词条表同口径：history 接口恢复历史时，
 * assistant 轮 content 为空但 tool_calls 非空（纯工具调用轮：选项卡/
 * 确认卡/起草等），合成可读摘要避免刷新后该轮不可见。
 */
class ToolSummaryLabels
{
    /** slug → 可读名称（覆盖高频工具；未收录的回退 slug 本身） */
    private const LABELS = [
        'ask_user_choice' => '提供选项待你选择',
        'activity_plan_draft' => '起草活动方案',
        'activity_plan_commit' => '定稿活动方案',
        'activity_status' => '查询活动进度',
        'navigate' => '导航到功能页面',
        'system_kb_search' => '检索系统知识库',
        'list_agents' => '查看数字员工名录',
        'delegate_to_agent' => '转派数字员工',
        'enable_agent' => '开通数字员工',
        'suggest_form_fill' => '生成表单填充建议',
        'start_task_chain' => '启动任务链',
        'advance_task_chain' => '推进任务链',
        'list_task_chains' => '查看任务链',
        'thread_review' => '查看工作脉络',
        'thread_track' => '建立工作脉络跟踪',
        'thread_untrack' => '取消工作脉络跟踪',
        'tag_customer' => '为客户打标签',
        'manage_tags' => '管理标签',
        'ai_auto_tag' => 'AI 自动打标',
        'create_script_draft' => '创建话术草稿',
        'create_distribution_plan' => '创建分销计划',
        'create_live_code' => '创建活码',
        'send_message' => '发送消息',
        'create_product' => '创建商品',
        'issue_coupon' => '发放优惠券',
        'create_sms_signature' => '创建短信签名',
        'send_sms_batch' => '群发短信',
        'schedule_sms_batch' => '定时群发短信',
        'create_poster' => '创建海报',
        'adjust_points' => '调整积分',
        'create_moments_sop' => '创建朋友圈 SOP',
        'create_mass_push' => '创建群发任务',
        'update_tenant_branding' => '更新品牌设置',
        'update_tenant_settings' => '更新租户设置',
        'update_tenant_domain' => '绑定自定义域名',
        'fetch_site_metadata' => '提取官网信息',
        'search_customer' => '查询客户',
        'create_event' => '创建活动',
    ];

    /** 单个工具的可读名称（未收录回退 slug） */
    public static function labelFor(string $slug): string
    {
        return self::LABELS[$slug] ?? $slug;
    }

    /**
     * 由 assistant 轮的 tool_calls 合成可读摘要
     *
     * @param  array<int, array<string, mixed>>  $toolCalls  落库的 tool_call 列表（name/slug 键兼容）
     */
    public static function summarizeToolCalls(array $toolCalls): string
    {
        $names = [];
        foreach ($toolCalls as $call) {
            $slug = (string) ($call['name'] ?? $call['slug'] ?? '');
            if ($slug === '' || in_array($slug, $names, true)) {
                continue;
            }
            $names[] = $slug;
        }

        if ($names === []) {
            return '';
        }

        $labels = array_map([self::class, 'labelFor'], array_slice($names, 0, 3));

        return '已为你执行：'.implode('、', $labels);
    }
}
