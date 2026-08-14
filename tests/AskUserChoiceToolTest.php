<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Ai\Services\Tool\AskUserChoiceTool;

/**
 * ask_user_choice 工具单元测试
 *
 * 覆盖：结构化载荷输出（action=user_choice）、单选/多选标记、
 * 选项归一化（trim/去空项）、缺参与选项数不足校验
 */
class AskUserChoiceToolTest extends TestCase
{
    private AskUserChoiceTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = new AskUserChoiceTool;
    }

    public function test_single_choice_returns_structured_payload(): void
    {
        $result = ($this->tool)([
            'question' => 'club.mtedu.com 是否已完成 ICP 备案？',
            'options' => ['是，已完成 ICP 备案', '否，尚未备案'],
        ], 1001);

        $this->assertSame('user_choice', $result['action']);
        $this->assertSame('club.mtedu.com 是否已完成 ICP 备案？', $result['question']);
        $this->assertSame(['是，已完成 ICP 备案', '否，尚未备案'], $result['options']);
        $this->assertFalse($result['multiple']);
    }

    public function test_multiple_flag_is_passed_through(): void
    {
        $result = ($this->tool)([
            'question' => '选择要启用的通知渠道',
            'options' => ['短信', '邮件', '站内信'],
            'multiple' => true,
        ], 1001);

        $this->assertTrue($result['multiple']);
        $this->assertCount(3, $result['options']);
    }

    public function test_options_are_normalized(): void
    {
        $result = ($this->tool)([
            'question' => '问题？',
            'options' => ['  选项一  ', '', '   ', '选项二'],
        ], 1001);

        $this->assertSame(['选项一', '选项二'], $result['options']);
    }

    public function test_empty_question_returns_error(): void
    {
        $result = ($this->tool)([
            'question' => '   ',
            'options' => ['是', '否'],
        ], 1001);

        $this->assertTrue($result['error']);
    }

    public function test_less_than_two_options_returns_error(): void
    {
        $result = ($this->tool)([
            'question' => '问题？',
            'options' => ['仅一个选项'],
        ], 1001);

        $this->assertTrue($result['error']);
    }

    public function test_non_array_options_returns_error(): void
    {
        $result = ($this->tool)([
            'question' => '问题？',
            'options' => '是',
        ], 1001);

        $this->assertTrue($result['error']);
    }
}
