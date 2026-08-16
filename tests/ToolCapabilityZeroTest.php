<?php

namespace MultiTenantSaas\Tests;

use PHPUnit\Framework\TestCase;

/**
 * 能力归零铁律回归测试（F5）
 *
 * 小助手不得拥有任何系统操作能力，即使只读命令（ls/cat）也不行；
 * 工具体系中禁止出现任何可执行系统命令/shell 的工具或方法。
 * 安全靠「能力不存在」保证，而非仅靠提示词或关键词拦截。
 *
 * 本测试静态扫描 AI 工具层源码，阻断未来引入：
 *  1. shell/进程执行能力（shell_exec/exec/passthru/popen/proc_open/pcntl_exec/Symfony Process）
 *  2. 审计旁路（AuditService::query —— 审计查询仅限平台后台，工具层零接触）
 *
 * 纯文件扫描，不启动 Laravel，秒级完成。配套 pre-commit 增量守卫
 * （scripts/architecture_guard.py::check_tool_layer_capability_zero）。
 */
class ToolCapabilityZeroTest extends TestCase
{
    /** 工具层目录（模块 Services/Tool 下全部 Handler/Tool 类） */
    private function toolLayerFiles(): array
    {
        $base = dirname(__DIR__).'/src/Modules';
        $files = [];

        foreach (glob($base.'/*/Services/Tool/*.php') ?: [] as $file) {
            $files[] = $file;
        }
        // 嵌套子目录（如 Tool/Handlers/）
        foreach (glob($base.'/*/Services/Tool/**/*.php') ?: [] as $file) {
            $files[] = $file;
        }

        return array_unique($files);
    }

    public function test_tool_layer_has_files(): void
    {
        $this->assertNotEmpty($this->toolLayerFiles(), '工具层目录扫描结果为空，glob 路径可能失效');
    }

    public function test_tool_layer_has_no_shell_execution_capability(): void
    {
        // 系统命令/shell 执行能力（与 architecture_guard.py 的 re_shell_exec 同口径）
        $pattern = '/\b(shell_exec|passthru|popen|proc_open|pcntl_exec)\s*\('
            .'|\b(exec|system)\s*\(\s*[\'"]'
            .'|->exec\s*\('
            .'|Symfony\\\\Component\\\\Process'
            .'|new\s+Process\s*\(/';

        $violations = [];
        foreach ($this->toolLayerFiles() as $file) {
            $content = (string) file_get_contents($file);
            if (preg_match($pattern, $content, $m)) {
                $violations[] = basename($file).' → '.$m[0];
            }
        }

        $this->assertSame(
            [],
            $violations,
            "能力归零铁律违规（AI 工具层禁止任何系统命令执行能力，即使只读命令）：\n".implode("\n", $violations)
        );
    }

    public function test_tool_layer_has_no_audit_access(): void
    {
        // 审计不可碰：工具层不得读/写审计（审计查询仅限平台后台）
        $pattern = '/AuditService::query|auditService\s*->\s*query/i';

        $violations = [];
        foreach ($this->toolLayerFiles() as $file) {
            $content = (string) file_get_contents($file);
            if (preg_match($pattern, $content, $m)) {
                $violations[] = basename($file).' → '.$m[0];
            }
        }

        $this->assertSame(
            [],
            $violations,
            "审计不可碰铁律违规（AI 工具层不得触碰审计）：\n".implode("\n", $violations)
        );
    }

    public function test_secretary_tools_do_not_include_audit_or_system_tools(): void
    {
        // 秘书工具白名单核查：注册表中不得出现审计/系统命令语义的工具
        $forbiddenSlugs = ['run_command', 'exec_command', 'shell', 'system_command', 'audit_log', 'query_audit'];

        $provider = file_get_contents(dirname(__DIR__).'/src/Modules/Ai/AiServiceProvider.php');
        $this->assertNotFalse($provider);

        foreach ($forbiddenSlugs as $slug) {
            $this->assertStringNotContainsString(
                "'{$slug}'",
                (string) $provider,
                "工具注册表出现禁用工具 slug：{$slug}"
            );
        }
    }
}
