<?php

declare(strict_types=1);

namespace MultiTenantSaas\Mcp;

/**
 * MCP Skill 文件/配置生成器
 *
 * 根据客户端类型和工具注册表，自动生成对应格式的 Skill 内容。
 * 支持三种客户端格式：WorkBuddy（Markdown）、Hermers（JSON）、OpenClaw（JSON）。
 */
class McpSkillGenerator
{
    /** 客户端类型常量 */
    public const CLIENT_WORKBUDDY = 'workbuddy';
    public const CLIENT_HERMERS   = 'hermers';
    public const CLIENT_OPENCLAW  = 'openclaw';

    /** 支持的客户端类型列表 */
    public const SUPPORTED_CLIENTS = [
        self::CLIENT_WORKBUDDY,
        self::CLIENT_HERMERS,
        self::CLIENT_OPENCLAW,
    ];

    /**
     * 工具注册表
     */
    protected McpToolRegistry $registry;

    public function __construct(McpToolRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * 生成指定客户端格式的 Skill 内容
     *
     * @param  string $clientType 客户端类型（workbuddy/hermers/openclaw）
     * @return string 生成的 Skill 内容
     *
     * @throws McpException 不支持的客户端类型时抛出
     */
    public function generate(string $clientType): string
    {
        return match ($clientType) {
            self::CLIENT_WORKBUDDY => $this->generateWorkBuddy(),
            self::CLIENT_HERMERS   => $this->generateHermers(),
            self::CLIENT_OPENCLAW  => $this->generateOpenClaw(),
            default                => throw McpException::invalidParams(
                "Unsupported client type [{$clientType}]. Supported: " . implode(', ', self::SUPPORTED_CLIENTS)
            ),
        };
    }

    /**
     * 生成 WorkBuddy Markdown Skill 格式
     */
    protected function generateWorkBuddy(): string
    {
        $tools = $this->registry->listTools();
        $lines = [];

        $lines[] = '# MCP Tools';
        $lines[] = '';
        $lines[] = 'Available tools for this agent.';
        $lines[] = '';

        foreach ($tools as $tool) {
            $lines[] = '## ' . $tool['name'];
            $lines[] = '';
            $lines[] = $tool['description'];
            $lines[] = '';

            $lines[] = '### Parameters';
            $lines[] = '';

            [$properties, $required] = $this->extractSchema($tool['inputSchema'] ?? []);

            if (empty($properties)) {
                $lines[] = 'No parameters.';
            } else {
                $lines[] = '| Name | Type | Required | Description |';
                $lines[] = '|------|------|----------|-------------|';

                foreach ($properties as $paramName => $paramDef) {
                    $type = str_replace('|', '\\|', $paramDef['type'] ?? 'any');
                    $desc = str_replace('|', '\\|', $paramDef['description'] ?? '');
                    $isRequired = in_array($paramName, $required) ? 'Yes' : 'No';
                    $lines[] = "| `{$paramName}` | {$type} | {$isRequired} | {$desc} |";
                }
            }

            $lines[] = '';
            $lines[] = '### Usage Example';
            $lines[] = '';
            $lines[] = '```json';
            $lines[] = $this->jsonEncode(
                $this->buildExamplePayload($tool['name'], $properties)
            );
            $lines[] = '```';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * 生成 Hermers JSON 配置格式
     */
    protected function generateHermers(): string
    {
        $tools = $this->registry->listTools();
        $config = [
            'version'     => '1.0',
            'client_type' => self::CLIENT_HERMERS,
            'tools'       => [],
        ];

        foreach ($tools as $tool) {
            [$properties] = $this->extractSchema($tool['inputSchema'] ?? []);

            $config['tools'][] = [
                'name'        => $tool['name'],
                'description' => $tool['description'],
                'parameters'  => $this->normalizeSchema($tool['inputSchema'] ?? []),
                'examples'    => [
                    $this->buildExamplePayload($tool['name'], $properties),
                ],
            ];
        }

        return $this->jsonEncode($config);
    }

    /**
     * 生成 OpenClaw JSON 配置格式
     */
    protected function generateOpenClaw(): string
    {
        $tools = $this->registry->listTools();
        $config = [
            'schema_version' => '2024-01',
            'platform'       => 'openclaw',
            'capabilities'   => [],
        ];

        foreach ($tools as $tool) {
            [$properties, $required] = $this->extractSchema($tool['inputSchema'] ?? []);

            $config['capabilities'][] = [
                'function' => [
                    'name'        => $tool['name'],
                    'description' => $tool['description'],
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => $properties,
                        'required'   => $required,
                    ],
                ],
                'examples' => [
                    [
                        'input'  => $this->buildExamplePayload($tool['name'], $properties),
                        'output' => '(result from tool execution)',
                    ],
                ],
            ];
        }

        return $this->jsonEncode($config);
    }

    /**
     * 从 inputSchema 中提取 properties 和 required
     *
     * 空 properties 返回空对象（JSON `{}`）而非空数组（JSON `[]`），
     * 以符合 JSON Schema 规范。
     *
     * @return array{0: array|\stdClass, 1: array}
     */
    protected function extractSchema(array $schema): array
    {
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];

        // 空 properties 转为空对象，确保 json_encode 输出 {}
        if (empty($properties)) {
            $properties = new \stdClass();
        }

        return [$properties, $required];
    }

    /**
     * 将 schema 归一化为 JSON 对象（空数组 → 空对象）
     *
     * @return array|\stdClass
     */
    protected function normalizeSchema(array $schema): array|\stdClass
    {
        if (empty($schema)) {
            return new \stdClass();
        }

        return $schema;
    }

    /**
     * 安全的 json_encode，失败时抛出 McpException
     */
    protected function jsonEncode(mixed $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            throw McpException::internalError('JSON encoding failed: ' . $e->getMessage(), $e);
        }
    }

    /**
     * 根据参数 schema 构建示例请求体
     *
     * @param  string $toolName   工具名称
     * @param  array  $properties 参数属性定义
     * @return array  示例请求体
     */
    protected function buildExamplePayload(string $toolName, array $properties): array
    {
        $payload = ['tool' => $toolName, 'params' => []];

        foreach ($properties as $paramName => $paramDef) {
            $payload['params'][$paramName] = $this->generateExampleValue((array) $paramDef);
        }

        return $payload;
    }

    /**
     * 根据参数定义生成示例值
     *
     * @param  array $paramDef 参数定义（含 type, enum, default 等）
     * @return mixed 示例值
     */
    protected function generateExampleValue(array $paramDef): mixed
    {
        // 优先使用 enum 的第一个值
        if (!empty($paramDef['enum'])) {
            return $paramDef['enum'][0];
        }

        // 使用 default 值
        if (array_key_exists('default', $paramDef)) {
            return $paramDef['default'];
        }

        // 根据类型生成示例
        return match ($paramDef['type'] ?? 'string') {
            'integer' => 0,
            'number'  => 0.0,
            'boolean' => true,
            'array'   => [],
            'object'  => (object) [],
            default   => 'example',
        };
    }
}
