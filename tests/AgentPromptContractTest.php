<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentTemplateRegistry;
use MultiTenantSaas\Modules\Ai\Services\Agent\BuiltinAgentTemplates;
use MultiTenantSaas\Modules\Ai\Services\SystemKb\ConsoleRouteMapGenerator;
use MultiTenantSaas\Tests\Schema\AgentModule;

/**
 * 模板提示词契约测试（CI 关卡）
 *
 * 数值确定性治理：prompt 是模型行为的事实源，其中出现的标识符与路由
 * 不得依赖模型记忆或人工对齐——
 * 1. prompt 中提取的 snake_case 标识符必须 ∈ 本模板工具 ∪ 已注册工具 ∪ 显式词表白名单；
 * 2. prompt 中引用的路由路径必须存在于前端 routes.ts 解析结果
 *    （复用 ConsoleRouteMapGenerator 的解析链路，动态段归一化匹配）。
 */
class AgentPromptContractTest extends TestCase
{
    protected array $uses = [AgentModule::class];

    /**
     * 字段/参数名词白名单（集中定义，新增名词须显式登记于此）
     *
     * 这些是工具返回值字段或调用参数名，不是工具 slug；
     * 出现在 prompt 中属于合法引用。
     */
    private const IDENTIFIER_VOCABULARY = [
        'agent_id', 'plan_id', 'handoff_message', 'available_to_enable',
        'form_state', 'next_action', 'step_input', 'step_output',
        'route_path', 'navigate_hint', 'tool_call_id',
        'activity_plan', 'entity_type', 'entity_id',
    ];

    /**
     * 下游提供的路由白名单（框架 standalone 部署无此页面，
     * 由下游 split 包注册；每条须注明提供方，禁止无据扩充）
     */
    private const DOWNSTREAM_ROUTE_WHITELIST = [
        // scrm-platform Activity 模块 ActivityManagement 页面（活动列表）
        '/activity',
        // scrm-platform Content 模块素材管理/话术库页面
        '/materials',
        '/scripts',
    ];

    /**
     * prompt 中路由引用的锚点上下文：仅提取「=路径」「（路径）」「[链接](路径)」
     * 「route_path: 路径」「跳转/带到 路径」，避免误伤 DROP/DELETE、eval/exec 等安全表述
     */
    private const ROUTE_ANCHOR_REGEX = '#(?:=|（|\]\(|route_path: |跳转|到 )\K(/[a-z][a-z0-9_\-{}]*(?:/[a-z][a-z0-9_\-{}]*)*)#';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // activity_plan/thread/task_chain 工具受引擎开关门控，全开才能校验 prompt 标识符的注册完整性
        $app['config']->set('ai.activity_plan.enabled', true);
        $app['config']->set('ai.task_chains.enabled', true);
        $app['config']->set('ai.brain.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        BuiltinAgentTemplates::clearCache();
        AgentTemplateRegistry::flush();
    }

    public function test_prompt_identifiers_belong_to_tools_or_vocabulary(): void
    {
        $registry = app(ToolRegistryContract::class);
        $checked = 0;

        foreach (AgentTemplateRegistry::definitions() as $template) {
            $prompt = (string) $template['system_prompt'];
            $allowed = array_merge($template['tools'], $template['optional_tools'], self::IDENTIFIER_VOCABULARY);

            preg_match_all('/[a-z][a-z0-9]*(?:_[a-z0-9]+)+/', $prompt, $matches);

            foreach (array_unique($matches[0]) as $identifier) {
                $checked++;

                if (in_array($identifier, $allowed, true)) {
                    continue;
                }

                $this->assertNotNull(
                    $registry->get($identifier),
                    "模板 [{$template['template_key']}] 的 prompt 含未注册工具标识符：{$identifier}"
                    .'（若为字段/参数名词，请登记进 IDENTIFIER_VOCABULARY 词表白名单）',
                );
            }
        }

        // prompt 必然含工具标识符引用（如 system_kb_search/plan_id），提取为 0 说明正则失效
        $this->assertGreaterThan(0, $checked, '未从 prompt 提取到任何 snake_case 标识符，提取正则可能失效');
    }

    public function test_prompt_routes_exist_in_frontend_routes_ts(): void
    {
        // 显式指定包根目录（测试环境 base_path() 指向 Testbench 骨架）
        $knownPaths = array_map(
            self::class.'::normalizePath',
            (new ConsoleRouteMapGenerator(dirname(__DIR__)))->routePaths(),
        );

        $this->assertNotEmpty($knownPaths, 'routes.ts 解析结果为空，契约关卡失去事实源');

        foreach (AgentTemplateRegistry::definitions() as $template) {
            preg_match_all(self::ROUTE_ANCHOR_REGEX, (string) $template['system_prompt'], $matches);

            foreach (array_unique($matches[0]) as $route) {
                if (in_array($route, self::DOWNSTREAM_ROUTE_WHITELIST, true)) {
                    continue;
                }

                $this->assertContains(
                    self::normalizePath($route),
                    $knownPaths,
                    "模板 [{$template['template_key']}] 的 prompt 引用了不存在的路由：{$route}"
                    .'（路由事实源为前端 routes.ts，见 ConsoleRouteMapGenerator）',
                );
            }
        }
    }

    /**
     * 路径归一化：动态段（{param} / :param / :param?）统一为通配 *，
     * 使 prompt 的 /events/{event_id} 与 routes.ts 的 events/:id 可比对
     */
    private static function normalizePath(string $path): string
    {
        $segments = array_map(
            static fn (string $segment): string => preg_match('/^(?:\{.+\}|:.+\??)$/', $segment) ? '*' : $segment,
            explode('/', $path),
        );

        return implode('/', $segments);
    }
}
