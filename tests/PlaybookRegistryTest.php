<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Campaign\Services\PlaybookRegistry;

/**
 * PlaybookRegistry 单测（docs/event-plan.md Phase 1：B1 + B2）
 *
 * 验证 playbook 注册、合并、校验逻辑：
 * - 内置 demo playbook 正确加载
 * - 下游扩展合并覆盖
 * - 非法定义记日志跳过
 * - catalog 轻量视图
 */
class PlaybookRegistryTest extends TestCase
{
    private function registry(): PlaybookRegistry
    {
        return new PlaybookRegistry;
    }

    // ── 内置 Playbook ──

    public function test_builtin_demo_sms_sequence_exists(): void
    {
        $pb = $this->registry()->find('demo_sms_sequence');

        $this->assertNotNull($pb);
        $this->assertSame('三天短信序列（演示）', $pb['title']);
        $this->assertArrayHasKey('skeleton', $pb);
        $this->assertSame('campaign.plan/v1', $pb['skeleton']['schema']);
        $this->assertNotEmpty($pb['skeleton']['phases']);
    }

    public function test_builtin_playbook_skeleton_has_valid_tasks(): void
    {
        $pb = $this->registry()->find('demo_sms_sequence');
        $tasks = $pb['skeleton']['phases'][0]['tasks'];

        $this->assertNotEmpty($tasks);
        $this->assertSame('daily_sms', $tasks[0]['key']);
        $this->assertSame('recurring', $tasks[0]['trigger']['type']);
        $this->assertSame('tool', $tasks[0]['action']['type']);
    }

    // ── 下游扩展合并 ──

    public function test_extra_playbook_classes_are_merged(): void
    {
        config(['ai.campaign.extra_playbook_classes' => [StubPlaybooks::class]]);

        $registry = $this->registry();

        $this->assertNotNull($registry->find('stub_playbook'));
        // 内置不受影响
        $this->assertNotNull($registry->find('demo_sms_sequence'));
    }

    public function test_downstream_overrides_builtin_by_key(): void
    {
        config(['ai.campaign.extra_playbook_classes' => [OverridePlaybooks::class]]);

        $registry = $this->registry();

        $pb = $registry->find('demo_sms_sequence');
        $this->assertSame('下游覆盖版短信序列', $pb['title']);
    }

    // ── 校验 ──

    public function test_invalid_playbook_skipped_without_breaking_others(): void
    {
        config(['ai.campaign.extra_playbook_classes' => [InvalidPlaybooks::class]]);

        $registry = $this->registry();

        // 坏定义被跳过
        $this->assertNull($registry->find('no_key'));
        $this->assertNull($registry->find('no_skeleton'));
        $this->assertNull($registry->find('empty_phases'));
        $this->assertNull($registry->find('task_no_key'));
        // 合法定义不受影响
        $this->assertNotNull($registry->find('demo_sms_sequence'));
    }

    // ── catalog ──

    public function test_catalog_returns_lightweight_view(): void
    {
        $catalog = $this->registry()->catalog();

        $this->assertNotEmpty($catalog);
        $first = $catalog[0];
        $this->assertArrayHasKey('key', $first);
        $this->assertArrayHasKey('title', $first);
        $this->assertArrayHasKey('description', $first);
        // 不含 skeleton（轻量）
        $this->assertArrayNotHasKey('skeleton', $first);
    }

    // ── 缓存 ──

    public function test_clear_cache_reloads_definitions(): void
    {
        $registry = $this->registry();

        // 首次加载
        $this->assertNotNull($registry->find('demo_sms_sequence'));
        $this->assertNull($registry->find('stub_playbook'));

        // 修改 config 后缓存仍返回旧结果
        config(['ai.campaign.extra_playbook_classes' => [StubPlaybooks::class]]);
        $this->assertNull($registry->find('stub_playbook'));

        // clearCache 后重新加载
        $registry->clearCache();
        $this->assertNotNull($registry->find('stub_playbook'));
    }
}

/**
 * 测试用：合法 playbook
 */
class StubPlaybooks
{
    public static function playbooks(): array
    {
        return [
            [
                'key' => 'stub_playbook',
                'title' => '桩 Playbook',
                'description' => '用于测试的桩 playbook',
                'methodology' => '测试方法论',
                'skeleton' => [
                    'schema' => 'campaign.plan/v1',
                    'phases' => [
                        [
                            'key' => 'phase_1',
                            'title' => '第一阶段',
                            'tasks' => [
                                [
                                    'key' => 'task_a',
                                    'title' => '任务 A',
                                    'trigger' => ['type' => 'at_time', 'time' => '2026-08-01 10:00'],
                                    'action' => ['type' => 'tool', 'tool' => 'send_sms'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}

/**
 * 测试用：下游覆盖内置 key
 */
class OverridePlaybooks
{
    public static function playbooks(): array
    {
        return [
            [
                'key' => 'demo_sms_sequence',
                'title' => '下游覆盖版短信序列',
                'skeleton' => [
                    'schema' => 'campaign.plan/v1',
                    'phases' => [
                        [
                            'key' => 'override_phase',
                            'title' => '覆盖阶段',
                            'tasks' => [
                                [
                                    'key' => 'override_task',
                                    'title' => '覆盖任务',
                                    'trigger' => ['type' => 'at_time', 'time' => '2026-09-01 10:00'],
                                    'action' => ['type' => 'tool', 'tool' => 'send_sms'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}

/**
 * 测试用：非法定义（各种校验不通过）
 */
class InvalidPlaybooks
{
    public static function playbooks(): array
    {
        return [
            // key 缺失
            ['title' => '无 key', 'skeleton' => ['phases' => []]],
            // skeleton 缺失
            ['key' => 'no_skeleton', 'title' => '无 skeleton'],
            // phases 为空
            ['key' => 'empty_phases', 'title' => '空 phases', 'skeleton' => ['phases' => []]],
            // task 缺少 key
            [
                'key' => 'task_no_key',
                'title' => '任务无 key',
                'skeleton' => [
                    'phases' => [
                        [
                            'key' => 'p1',
                            'tasks' => [
                                ['title' => '无 key 任务', 'trigger' => ['type' => 'at_time'], 'action' => ['type' => 'tool']],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
