<?php

namespace MultiTenantSaas\Services\Agent\Dto;

/**
 * Agent 工具定义 DTO
 *
 * 封装 Agent 可用工具的元数据，包括工具标识、描述、参数 Schema 和处理器类名。
 * 用于 ToolRegistry 内部管理和 Function Calling 格式转换。
 *
 * 字段说明：
 *  - slug:           工具唯一标识（如 search_customer）
 *  - name:           工具显示名称
 *  - description:    工具功能描述（供 AI 理解工具用途）
 *  - parametersSchema: JSON Schema 格式的参数定义（供 AI 生成调用参数）
 *  - handlerClass:   工具处理器类名（FQCN，实际执行工具逻辑）
 */
final class Tool
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $description,
        public readonly array $parametersSchema,
        public readonly string $handlerClass,
    ) {}

    /**
     * 从数组构造
     *
     * @param  array  $data  {
     *                       slug: string,
     *                       name: string,
     *                       description: string,
     *                       parameters_schema: array,
     *                       handler_class: string
     *                       }
     */
    public static function fromArray(array $data): static
    {
        return new self(
            slug: (string) ($data['slug'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            parametersSchema: (array) ($data['parameters_schema'] ?? []),
            handlerClass: (string) ($data['handler_class'] ?? ''),
        );
    }

    /**
     * 转换为 Function Calling 格式
     *
     * 将工具定义转换为 OpenAI Function Calling 所需的 JSON 结构。
     *
     * @return array {
     *               type: 'function',
     *               function: {
     *                 name: string,
     *                 description: string,
     *                 parameters: array
     *               }
     *               }
     */
    public function toFunctionCalling(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->slug,
                'description' => $this->description,
                'parameters' => $this->parametersSchema,
            ],
        ];
    }

    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'parameters_schema' => $this->parametersSchema,
            'handler_class' => $this->handlerClass,
        ];
    }
}
