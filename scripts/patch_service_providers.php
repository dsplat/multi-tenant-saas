#!/usr/bin/env php
<?php

/**
 * 为所有模块 ServiceProvider 注入 registerTools() 方法
 *
 * 策略：
 * 1. 如果已有 bootModule() → 在方法体开头插入 $this->registerTools();
 * 2. 如果没有 bootModule() → 添加 bootModule() { $this->registerTools(); }
 * 3. 在类的最后一个 } 前插入 registerTools() 方法
 * 4. 添加 use 语句
 */
$basePath = dirname(__DIR__) . '/src/Modules';

// 从 generate_module_tools.php 提取 $modules 定义
ob_start();
// 不执行生成，只提取定义
$scriptContent = file_get_contents(dirname(__DIR__) . '/scripts/generate_module_tools.php');
// 提取 $modules = [...] 部分
preg_match('/\$modules\s*=\s*\[(.*?)\n\];/s', $scriptContent, $matches);
eval('$modules = [' . $matches[1] . '];');
ob_end_clean();

$patched = 0;

foreach ($modules as $module => $config) {
    $spFile = "$basePath/$module/{$module}ServiceProvider.php";
    if (! file_exists($spFile)) {
        echo "SKIP: $spFile not found\n";

        continue;
    }

    $content = file_get_contents($spFile);

    // 检查是否已有 registerTools
    if (str_contains($content, 'registerTools')) {
        // 已有 registerTools，需要替换为完整版
        echo "REPLACE: $module (existing registerTools)\n";
        $content = replaceRegisterTools($content, $module, $config);
    } else {
        echo "PATCH: $module\n";
        $content = patchServiceProvider($content, $module, $config);
    }

    file_put_contents($spFile, $content);
    $patched++;
}

echo "\nPatched: $patched ServiceProviders\n";

function patchServiceProvider(string $content, string $module, array $config): string
{
    // 1. 添加 use 语句
    $useStatements = "use MultiTenantSaas\\Contracts\\ToolRegistryContract;\n";
    foreach ($config['tools'] as $tool) {
        $className = $tool[1];
        $useStatements .= "use {$config['namespace']}\\{$className};\n";
    }

    // 在 class 声明前插入 use
    $content = preg_replace(
        '/(class\s+' . $module . 'ServiceProvider)/',
        $useStatements . "\n$1",
        $content,
        1
    );

    // 2. 处理 bootModule
    if (preg_match('/protected function bootModule\(\): void\s*\{/', $content)) {
        // 已有 bootModule，在 { 后插入 $this->registerTools();
        $content = preg_replace(
            '/(protected function bootModule\(\): void\s*\{)\s*\n/',
            "$1\n        \$this->registerTools();\n",
            $content,
            1
        );
    } else {
        // 没有 bootModule，在 registerModuleBindings 方法后添加
        $bootMethod = "\n    protected function bootModule(): void\n    {\n        \$this->registerTools();\n    }\n";

        // 在类结尾的 } 前添加
        $lastBrace = strrpos($content, '}');
        $content = substr($content, 0, $lastBrace) . $bootMethod . "\n" . generateRegisterTools($config) . "\n}\n";

        return $content;
    }

    // 3. 在类结尾 } 前插入 registerTools()
    $lastBrace = strrpos($content, '}');
    $content = substr($content, 0, $lastBrace) . generateRegisterTools($config) . "\n}\n";

    return $content;
}

function replaceRegisterTools(string $content, string $module, array $config): string
{
    // 替换已有的 registerTools 方法
    $content = preg_replace(
        '/    private function registerTools\(\): void\s*\{.*?\n    \}/s',
        generateRegisterTools($config),
        $content
    );

    // 更新 use 语句（添加缺失的 handler imports）
    foreach ($config['tools'] as $tool) {
        $className = $tool[1];
        $useStatement = "use {$config['namespace']}\\{$className};";
        if (! str_contains($content, $useStatement)) {
            // 在最后一个 use 语句后添加
            $content = preg_replace(
                '/(use [^;]+;\n)(?!use )/',
                "$1{$useStatement}\n",
                $content,
                1
            );
        }
    }

    return $content;
}

function generateRegisterTools(array $config): string
{
    $lines = [];
    $lines[] = '    private function registerTools(): void';
    $lines[] = '    {';
    $lines[] = '        $registry = app(ToolRegistryContract::class);';
    $lines[] = '';

    foreach ($config['tools'] as $tool) {
        [$slug, $className, $method, $risk, $params] = $tool;
        $name = ucwords(str_replace('_', ' ', $slug));
        $desc = ucfirst(str_replace('_', ' ', preg_replace('/^[a-z]+_/', '', $slug)));

        // Build schema
        $props = [];
        $required = [];
        foreach ($params as $pName => $def) {
            $parts = explode(',', $def);
            $type = match ($parts[0]) {
                'integer' => 'integer',
                'number' => 'number',
                'array' => 'array',
                'object' => 'object',
                default => 'string',
            };
            $description = $parts[1] ?? '';
            $props[] = "'$pName' => ['type' => '$type', 'description' => '$description']";
            if (isset($parts[2]) && $parts[2] === '1') {
                $required[] = "'$pName'";
            }
        }

        $schema = "['type' => 'object', 'properties' => [" . implode(', ', $props) . ']';
        if ($required) {
            $schema .= ", 'required' => [" . implode(', ', $required) . ']';
        }
        $schema .= ']';

        $category = strtolower(basename(str_replace('\\', '/', $config['namespace'])));
        // 从 namespace 提取模块名作为 category
        preg_match('/Modules\\\\(\w+)\\\\/', $config['namespace'], $m);
        $category = strtolower($m[1] ?? 'core');

        $lines[] = "        \$registry->register('$slug', '$name', '$desc', {$className}::class, $schema, '$category', '$risk');";
    }

    $lines[] = '    }';

    return implode("\n", $lines);
}
