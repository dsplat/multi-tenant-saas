<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services;

use MultiTenantSaas\Modules\Ai\DTOs\PageContext;

/**
 * 意图路由器。
 *
 * 根据页面上下文路由到对应 agent slug。
 * 路由策略：
 * 1. user_intent 关键词匹配（优先）
 * 2. module → 默认 agent slug 映射
 * 3. 无法识别 → 通用助手 slug 或 null
 */
class IntentRouter
{
    /**
     * 模块 → 默认 agent slug 映射表。
     * 项目层可通过 config('ai.intent_routes.modules') 覆盖。
     */
    private array $moduleRoutes = [];

    /**
     * 关键词 → agent slug 映射表。
     * 项目层可通过 config('ai.intent_routes.keywords') 覆盖。
     */
    private array $keywordRoutes = [];

    /**
     * 通用助手 agent slug（兜底）。
     */
    private ?string $defaultAgentSlug = null;

    public function __construct()
    {
        $this->moduleRoutes = config('ai.intent_routes.modules', $this->defaultModuleRoutes());
        $this->keywordRoutes = config('ai.intent_routes.keywords', $this->defaultKeywordRoutes());
        $this->defaultAgentSlug = config('ai.intent_routes.default_agent', 'general-assistant');
    }

    /**
     * 根据页面上下文路由到对应 agent slug。
     *
     * @return string|null agent slug，null 表示拒绝（无可用路由）
     */
    public function route(PageContext $ctx): ?string
    {
        // 1. 关键词匹配（用户意图优先）
        if ($ctx->userIntent !== null && $ctx->userIntent !== '') {
            $slug = $this->matchByKeywords($ctx->userIntent);
            if ($slug !== null) {
                return $slug;
            }
        }

        // 2. 模块映射
        $moduleKey = mb_strtolower($ctx->module);
        if (isset($this->moduleRoutes[$moduleKey])) {
            return $this->moduleRoutes[$moduleKey];
        }

        // 3. 兜底
        return $this->defaultAgentSlug;
    }

    /**
     * 注册模块路由（运行时扩展）。
     */
    public function registerModuleRoute(string $module, string $agentSlug): void
    {
        $this->moduleRoutes[mb_strtolower($module)] = $agentSlug;
    }

    /**
     * 注册关键词路由（运行时扩展）。
     *
     * @param  array<string>  $keywords
     */
    public function registerKeywordRoute(array $keywords, string $agentSlug): void
    {
        foreach ($keywords as $keyword) {
            $this->keywordRoutes[mb_strtolower($keyword)] = $agentSlug;
        }
    }

    /**
     * 关键词匹配：遍历关键词表，找到第一个命中。
     */
    private function matchByKeywords(string $intent): ?string
    {
        $intentLower = mb_strtolower($intent);

        foreach ($this->keywordRoutes as $keyword => $slug) {
            if (str_contains($intentLower, $keyword)) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * 默认模块路由表（框架内置，项目层可覆盖）。
     */
    private function defaultModuleRoutes(): array
    {
        return [
            'marketing' => 'marketing-assistant',
            'sales' => 'sales-assistant',
            'customer' => 'customer-assistant',
            'conversation' => 'conversation-assistant',
            'billing' => 'billing-assistant',
            'workflow' => 'workflow-assistant',
        ];
    }

    /**
     * 默认关键词路由表。
     */
    private function defaultKeywordRoutes(): array
    {
        return [
            '写' => 'content-assistant',
            '生成' => 'content-assistant',
            '翻译' => 'content-assistant',
            '改写' => 'content-assistant',
            '分析' => 'data-assistant',
            '统计' => 'data-assistant',
            '报表' => 'data-assistant',
            '标签' => 'customer-assistant',
            '客户' => 'customer-assistant',
            '工单' => 'support-assistant',
        ];
    }
}
