<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentTemplateRegistry;
use MultiTenantSaas\Modules\Ai\Services\Agent\BuiltinAgentTemplates;
use MultiTenantSaas\Tests\Schema\AgentModule;

/**
 * 模板工具清单一致性契约测试（CI 关卡）
 *
 * 数值确定性治理：模板 tools 是「必须已注册」意图，缺失即失败（fail-fast）；
 * optional_tools 是「下游扩展」意图，未注册静默跳过属设计意图。
 * 引擎开关（campaign/task_chains/brain）在此全开，校验开关全开下的注册完整性。
 */
class AgentTemplateToolsConsistencyTest extends TestCase
{
    protected array $uses = [AgentModule::class];

    /**
     * 契约关卡在引擎开关全开下运行：三个开关关闭时对应工具不注册，
     * 只有全开才能校验模板 tools 的注册完整性
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('ai.campaign.enabled', true);
        $app['config']->set('ai.task_chains.enabled', true);
        $app['config']->set('ai.brain.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        BuiltinAgentTemplates::clearCache();
        AgentTemplateRegistry::flush();
    }

    public function test_all_template_tools_are_registered(): void
    {
        $registry = app(ToolRegistryContract::class);

        foreach (AgentTemplateRegistry::definitions() as $template) {
            foreach ($template['tools'] as $slug) {
                $this->assertNotNull(
                    $registry->get($slug),
                    "模板 [{$template['template_key']}] 的 tools 含未注册工具：{$slug}"
                    .'（tools 为必须注册意图；下游扩展工具请改用 optional_tools）',
                );
            }
        }
    }

    public function test_tools_and_optional_tools_are_disjoint(): void
    {
        foreach (AgentTemplateRegistry::definitions() as $template) {
            $intersection = array_values(array_intersect($template['tools'], $template['optional_tools']));

            $this->assertSame(
                [],
                $intersection,
                "模板 [{$template['template_key']}] 的 tools 与 optional_tools 存在交集：".implode(',', $intersection),
            );
        }
    }

    public function test_secretary_optional_tools_cover_downstream_l2_tools(): void
    {
        $secretary = AgentTemplateRegistry::findByKey('system_secretary');

        $this->assertNotNull($secretary);
        // 下游 L2 代操作工具均落 optional_tools（纯框架部署未注册时 fail-open 跳过）
        $this->assertContains('tag_customer', $secretary['optional_tools']);
        $this->assertContains('send_message', $secretary['optional_tools']);
        // 框架自有工具仍属 tools（必须注册）
        $this->assertContains('navigate', $secretary['tools']);
        $this->assertNotContains('navigate', $secretary['optional_tools']);
    }
}
