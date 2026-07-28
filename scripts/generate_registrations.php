#!/usr/bin/env php
<?php

/**
 * 生成 ServiceProvider registerTools() 注册代码
 * 输出到 /tmp/tool_registrations/<Module>.php
 */

// 工具定义（同 generate_module_tools.php 的结构）
$modules = require __DIR__ . '/tool_definitions.php';

$outDir = '/tmp/tool_registrations';
if (! is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

foreach ($modules as $module => $config) {
    $lines = [];
    $lines[] = '    private function registerTools(): void';
    $lines[] = '    {';
    $lines[] = '        $registry = app(ToolRegistryContract::class);';
    $lines[] = '';

    foreach ($config['tools'] as $tool) {
        [$slug, $className, $method, $risk, $params] = $tool;
        $name = slugToName($slug);
        $desc = slugToDescription($slug, $method);

        // Build schema
        $props = [];
        $required = [];
        foreach ($params as $pName => $def) {
            $parts = explode(',', $def);
            $type = $parts[0];
            $description = $parts[1] ?? '';
            $isRequired = isset($parts[2]) && $parts[2] === '1';

            $jsonType = match ($type) {
                'integer' => 'integer',
                'number' => 'number',
                'array' => 'array',
                'object' => 'object',
                default => 'string',
            };

            $prop = "'$pName' => ['type' => '$jsonType', 'description' => '$description']";
            $props[] = $prop;
            if ($isRequired) {
                $required[] = "'$pName'";
            }
        }

        $schemaStr = "['type' => 'object', 'properties' => [" . implode(', ', $props) . ']';
        if (! empty($required)) {
            $schemaStr .= ", 'required' => [" . implode(', ', $required) . ']';
        }
        $schemaStr .= ']';

        $handlerClass = "{$className}::class";
        $category = strtolower($module);
        $riskStr = $risk === 'L2' ? "'L2'" : "'L1'";

        $lines[] = "        \$registry->register('$slug', '$name', '$desc', $handlerClass, $schemaStr, '$category', $riskStr);";
    }

    $lines[] = '    }';

    file_put_contents("$outDir/$module.php", implode("\n", $lines) . "\n");
    echo "$module: " . count($config['tools']) . " tools registered\n";
}

echo "\nOutput: $outDir/\n";

function slugToName(string $slug): string
{
    return ucwords(str_replace(['_', '-'], ' ', $slug));
}

function slugToDescription(string $slug, string $method): string
{
    // Convert camelCase method to readable description
    $words = preg_split('/(?=[A-Z])/', $method, -1, PREG_SPLIT_NO_EMPTY);

    return ucfirst(strtolower(implode(' ', $words)));
}
