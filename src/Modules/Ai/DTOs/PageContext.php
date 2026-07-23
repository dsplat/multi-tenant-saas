<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\DTOs;

/**
 * 前端页面上下文协议。
 *
 * 标准化前端页面上下文 → 意图路由 → agent 的入参。
 * 由前端采集、经 AssistantController 传入。
 */
class PageContext
{
    public function __construct(
        /** 前端路由（如 marketing.campaign.create） */
        public readonly string $route,
        /** 模块名（Marketing） */
        public readonly string $module,
        /** 实体类型（campaign） */
        public readonly ?string $entityType = null,
        /** 实体 ID（编辑时有值） */
        public readonly ?int $entityId = null,
        /** 当前表单状态 */
        public readonly array $formState = [],
        /** 页面可见数据摘要 */
        public readonly string $visibleDataSummary = '',
        /** 用户自然语言意图 */
        public readonly ?string $userIntent = null,
    ) {}

    /**
     * 从请求数组构建。
     */
    public static function fromArray(array $data): static
    {
        return new static(
            route: $data['route'] ?? '',
            module: $data['module'] ?? '',
            entityType: $data['entity_type'] ?? null,
            entityId: isset($data['entity_id']) ? (int) $data['entity_id'] : null,
            formState: $data['form_state'] ?? [],
            visibleDataSummary: $data['visible_data_summary'] ?? '',
            userIntent: $data['user_intent'] ?? null,
        );
    }

    /**
     * 转为 Agent 可理解的上下文摘要。
     */
    public function toPromptContext(): string
    {
        $parts = [
            "当前页面: {$this->route}",
            "模块: {$this->module}",
        ];

        if ($this->entityType) {
            $entity = $this->entityId
                ? "{$this->entityType}#{$this->entityId}"
                : $this->entityType;
            $parts[] = "实体: {$entity}";
        }

        if ($this->visibleDataSummary !== '') {
            $parts[] = "页面数据: {$this->visibleDataSummary}";
        }

        if (! empty($this->formState)) {
            $parts[] = '表单状态: '.json_encode($this->formState, JSON_UNESCAPED_UNICODE);
        }

        return implode("\n", $parts);
    }

    /**
     * 是否为编辑场景（有实体 ID）。
     */
    public function isEditing(): bool
    {
        return $this->entityId !== null;
    }
}
