<?php

declare(strict_types=1);

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Ai\Services\Tool\AskUserChoiceTool;
use MultiTenantSaas\Modules\Ai\Services\Tool\NavigateTool;

/**
 * 工具返回契约测试 —— 表述锁机械关卡
 *
 * 输出契约（Output Contract）：凡产生用户可见卡片/交互的工具，返回必须携带
 * 表述锁字段（status / next_action）——卡片位置、正文约束、下一步指引等事实
 * 一律由代码确定性给出，模型的转述据此锁定，不依赖提示词与模型自觉。
 * 工具返回是紧贴模型下一次输出的上下文，是全链路最强锁定点。
 *
 * campaign 系工具（draft/commit）的表述锁断言见 CampaignPlanToolTest。
 */
class ToolResultContractTest extends TestCase
{
    public function test_ask_user_choice_result_locks_expression(): void
    {
        $result = (new AskUserChoiceTool)([
            'question' => 'club.example.com 是否已完成 ICP 备案？',
            'options' => ['是，已完成 ICP 备案', '否，尚未备案'],
        ], 1);

        $this->assertSame('user_choice', $result['action']);
        // 表述锁：卡片位置事实 + 正文禁止重复问题/罗列选项
        $this->assertArrayHasKey('status', $result);
        $this->assertStringContainsString('下方', $result['status']);
        $this->assertStringContainsString('严禁重复问题原文', $result['status']);
    }

    public function test_navigate_result_locks_expression(): void
    {
        $result = (new NavigateTool)([
            'route_path' => '/campaign/calendar',
            'label' => '活动日历',
        ], 1);

        $this->assertSame('navigate', $result['action']);
        // 表述锁：正文引用页面必须 Markdown 链接，杜绝裸路径外泄
        $this->assertArrayHasKey('status', $result);
        $this->assertStringContainsString('Markdown 链接', $result['status']);
    }
}
