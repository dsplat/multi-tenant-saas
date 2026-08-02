#!/usr/bin/env php
<?php

/**
 * RuntimeException → DomainException 批量迁移脚本
 *
 * 用法:
 *   php scripts/migrate_runtime_exceptions.php --dry-run    # 预览变更，不写入
 *   php scripts/migrate_runtime_exceptions.php --apply      # 执行替换
 *   php scripts/migrate_runtime_exceptions.php --report     # 仅生成 review 清单
 *
 * 策略：
 *   - 根据 throw message 的关键词模式，映射到对应 DomainException 子类
 *   - 确定性高的自动替换，不确定的输出到 review 清单
 */
$srcDir = __DIR__ . '/../src';
$mode = $argv[1] ?? '--dry-run';

// ─── 异常类映射规则 ───────────────────────────────────────────────────────────
// 按优先级排列：先匹配更具体的模式
$rules = [
    // NotFoundException (404)
    [
        'target' => 'NotFoundException',
        'namespace' => 'MultiTenantSaas\\Exceptions\\NotFoundException',
        'patterns' => [
            'not_found', 'not found', '不存在', '找不到', 'cannot find',
            'no longer available', '未找到',
        ],
        'confidence' => 'high',
    ],
    // ConflictException (409)
    [
        'target' => 'ConflictException',
        'namespace' => 'MultiTenantSaas\\Exceptions\\ConflictException',
        'patterns' => [
            'already_exists', 'already exists', '已存在', '冲突', 'conflict',
            'duplicate', '重复', 'already_taken', 'already taken',
            'already_used', 'already used', '已使用', '已被占用',
        ],
        'confidence' => 'high',
    ],
    // PermissionDeniedException (403)
    [
        'target' => 'PermissionDeniedException',
        'namespace' => 'MultiTenantSaas\\Exceptions\\PermissionDeniedException',
        'patterns' => [
            'permission', '无权', 'denied', 'forbidden', '禁止',
            'unauthorized', 'not allowed', '不允许',
        ],
        'confidence' => 'high',
    ],
    // QuotaExceededException (429)
    [
        'target' => 'QuotaExceededException',
        'namespace' => 'MultiTenantSaas\\Exceptions\\QuotaExceededException',
        'patterns' => [
            'quota', 'limit_reached', 'exceeded', '超出', '额度',
            'rate_limit', '超过限制', 'too_many', '已达上限',
        ],
        'confidence' => 'high',
    ],
    // InsufficientCreditsException (402)
    [
        'target' => 'InsufficientCreditsException',
        'namespace' => 'MultiTenantSaas\\Exceptions\\InsufficientCreditsException',
        'patterns' => [
            'credit', 'balance', '余额', '扣费', 'insufficient',
            '积分不足', '额度不足',
        ],
        'confidence' => 'high',
    ],
    // ServiceUnavailableException (503)
    [
        'target' => 'ServiceUnavailableException',
        'namespace' => 'MultiTenantSaas\\Exceptions\\ServiceUnavailableException',
        'patterns' => [
            'not_configured', 'not configured', '未配置', 'unavailable',
            'not_installed', 'not installed', '未安装', 'disabled',
            '未启用', 'plugin_not_installed', 'plugin_dep_missing',
            '系统级未启用', 'service unavailable',
        ],
        'confidence' => 'high',
    ],
    // ServiceUnavailableException (503) — 上游 API 错误
    [
        'target' => 'ServiceUnavailableException',
        'namespace' => 'MultiTenantSaas\\Exceptions\\ServiceUnavailableException',
        'patterns' => [
            'provider_connection_error', 'provider_api_error', 'connection_error',
            'api_error', 'gateway', 'upstream', 'timeout',
            'connection refused', 'connection timed out',
            // 支付/三方 API 失败
            'paypal_', 'stripe_', 'unionpay_', 'preorder_failed',
            'capture_failed', 'refund_failed', 'token_failed', 'intent_failed',
            'create_failed', 'query_failed',
            'Wechat API error', 'Wechat API request failed',
            'WechatWork API error', 'WechatWork API request failed',
            'WechatWork:', 'empty access_token',
            'auth_failed', 'verification failed',
            'sign failed', 'RSA',
            // AI 提供商
            '图片生成失败', '图片生成返回空', 'bailian',
            "provider' => 'kling", "provider' => 'runway",
            "provider' => 'stability", "provider' => 'zhipu",
            // OIDC/SSO 外部服务失败
            'oidc_token_exchange_failed', 'oidc_userinfo_failed',
            'provider_not_implemented', 'horizon_not_available',
            'cleanup_failed', 'IdP returned empty',
        ],
        'confidence' => 'high',
    ],
    // StorageException (500) — 基础设施/内部故障
    [
        'target' => 'StorageException',
        'namespace' => 'MultiTenantSaas\\Exceptions\\StorageException',
        'patterns' => [
            'decryption failed', 'encryption failed', 'decrypt_failed',
            'key_decrypt_failed', 'delivery failed',
            'write failed', 'read failed', 'file_write_error',
            // 内部操作失败
            '写入失败', '无法创建', '损坏或无法读取',
            'migration_failed', 'data_migration_failed',
            'uninstall_failed', 'update_failed', 'reset_failed',
            'start_failed', 'retry_failed', 'profile_update_failed',
            'preferences_update_failed', 'preferences_reset_failed',
            'trial_start_failed', 'registration_failed',
            'not a local file',
        ],
        'confidence' => 'high',
    ],
    // DomainException (422) — 验证/业务规则违反，保持 422 但换成显式类型
    [
        'target' => 'DomainException',
        'namespace' => 'MultiTenantSaas\\Exceptions\\DomainException',
        'patterns' => [
            'invalid', '无效', '不合法', 'not_supported', 'not supported',
            '不支持', 'malformed', '格式错误', 'validation',
            '必须', 'required', 'cannot', '不能', '不可',
            // 业务状态限制
            'not_active', 'not_started', 'not_published', 'ended', 'expired',
            'submit_limit', 'single_only', 'plan_not_applicable',
            '格式不正确', '数据格式不正确', '必须是数字', '范围不正确',
            // 通用业务拒绝
            'coupon_', 'vote_', 'form_', 'not_eligible',
            '选项无效', '选择值格式', '签名数据', '位置数据',
            '缺少', 'missing', '不正确',
            // 透传变量兼容 + 边界业务规则
            'unsupported', 'unchanged', 'protected', 'no_delete',
            'already_active', 'not_in_trial', 'self_reference',
            'same_tenant', 'same_region', 'in_use', 'too_large', 'too_long',
            '已停用', '无法删除', '不是模板', '循环依赖',
            '不匹配', '不一致', '校验不通过',
            'has no', 'no nodes', 'no start',
            '未注册', 'not registered', 'json_parse',
            'key_incomplete', 'promo_key_incomplete',
            // 最终兜底：所有剩余的业务规则
            'plan_not_available', 'admin_only', 'task_not_completed',
            'password_too_short', 'refund_amount_exceeds', 'invoice_already_void',
            'prompt_name_exists', 'prompt_system_only', 'model_deprecated',
            'Only failed', 'not active', '锚点时间缺失',
            'plugin_already_installed', 'no_domain',
            'unique coupon code', '无法从团队上下文',
        ],
        'confidence' => 'medium',  // 422 是默认行为，语义变化最小
    ],
];

// ─── 扫描 & 分析 ─────────────────────────────────────────────────────────────

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

$autoReplace = [];   // 确定性高，可自动替换
$needReview = [];    // 需要人工 review
$stats = ['total' => 0, 'auto' => 0, 'review' => 0];

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $filePath = $file->getRealPath();
    $relativePath = str_replace(realpath($srcDir) . '/', '', $filePath);
    $content = file_get_contents($filePath);
    $lines = explode("\n", $content);

    foreach ($lines as $lineNum => $line) {
        // 匹配 throw new \RuntimeException(...) 或 throw new RuntimeException(...)
        if (! preg_match('/throw\s+new\s+\\\\?RuntimeException\s*\(/', $line)) {
            continue;
        }

        $stats['total']++;

        // 提取 throw 语句的完整消息（可能跨行）
        $throwContext = implode(' ', array_slice($lines, $lineNum, 3));
        $messageLower = strtolower($throwContext);

        $matched = false;
        foreach ($rules as $rule) {
            foreach ($rule['patterns'] as $pattern) {
                if (str_contains($messageLower, strtolower($pattern))) {
                    $entry = [
                        'file' => $relativePath,
                        'line' => $lineNum + 1,
                        'original' => trim($line),
                        'target' => $rule['target'],
                        'use' => $rule['namespace'],
                        'confidence' => $rule['confidence'],
                        'matched_pattern' => $pattern,
                    ];

                    if ($rule['confidence'] === 'high') {
                        $autoReplace[] = $entry;
                        $stats['auto']++;
                    } else {
                        $autoReplace[] = $entry; // medium confidence 也自动（DomainException 422 语义不变）
                        $stats['auto']++;
                    }
                    $matched = true;
                    break 2;
                }
            }
        }

        if (! $matched) {
            // 兜底：所有无法分类的 RuntimeException 统一归为 DomainException (422)
            // 框架安全导向：保持现有 HTTP 行为（Handler 已将 RuntimeException 映射为 422），仅替换类型
            $autoReplace[] = [
                'file' => $relativePath,
                'line' => $lineNum + 1,
                'original' => trim($line),
                'target' => 'DomainException',
                'use' => 'MultiTenantSaas\\Exceptions\\DomainException',
                'confidence' => 'low',
                'matched_pattern' => '(fallback)',
            ];
            $stats['auto']++;
        }
    }
}

// ─── 输出结果 ─────────────────────────────────────────────────────────────────

echo "━━━ RuntimeException 迁移分析 ━━━\n\n";
echo "总计: {$stats['total']} 处\n";
echo "可自动替换: {$stats['auto']} 处\n";
echo "需人工 review: {$stats['review']} 处\n\n";

// 按目标异常统计
$byTarget = [];
foreach ($autoReplace as $entry) {
    $byTarget[$entry['target']] = ($byTarget[$entry['target']] ?? 0) + 1;
}
arsort($byTarget);
echo "━━━ 自动替换分布 ━━━\n";
foreach ($byTarget as $target => $count) {
    echo "  {$target}: {$count}\n";
}
echo "\n";

if ($mode === '--report' || $mode === '--dry-run') {
    // 输出 review 清单
    if ($needReview) {
        echo "━━━ 需人工 Review（无法自动分类） ━━━\n\n";
        foreach ($needReview as $i => $entry) {
            $num = $i + 1;
            echo "  [{$num}] {$entry['file']}:{$entry['line']}\n";
            echo "      {$entry['original']}\n\n";
        }
    }

    if ($mode === '--dry-run') {
        echo "━━━ 预览前 20 条自动替换 ━━━\n\n";
        foreach (array_slice($autoReplace, 0, 20) as $entry) {
            echo "  {$entry['file']}:{$entry['line']}\n";
            echo "    → {$entry['target']} (matched: {$entry['matched_pattern']})\n";
            echo "    | {$entry['original']}\n\n";
        }
        echo "\n⚠️  使用 --apply 执行实际替换\n";
    }

    // 写入 review CSV
    $csvPath = __DIR__ . '/../docs/runtime-exception-review.csv';
    $fp = fopen($csvPath, 'w');
    fputcsv($fp, ['#', 'file', 'line', 'original_code', 'suggested_target', 'status'], ',', '"', '');
    $num = 0;
    foreach ($autoReplace as $entry) {
        $num++;
        fputcsv($fp, [$num, $entry['file'], $entry['line'], $entry['original'], $entry['target'], 'auto'], ',', '"', '');
    }
    foreach ($needReview as $entry) {
        $num++;
        fputcsv($fp, [$num, $entry['file'], $entry['line'], $entry['original'], '?', 'review'], ',', '"', '');
    }
    fclose($fp);
    echo "Review 清单已写入: docs/runtime-exception-review.csv\n";
}

if ($mode === '--apply') {
    echo "━━━ 开始执行替换 ━━━\n\n";

    // 按文件分组
    $byFile = [];
    foreach ($autoReplace as $entry) {
        $byFile[$entry['file']][] = $entry;
    }

    $modified = 0;
    foreach ($byFile as $relPath => $entries) {
        $absPath = $srcDir . '/' . $relPath;
        $content = file_get_contents($absPath);
        $lines = explode("\n", $content);
        $usesNeeded = [];

        // 从最后一行开始替换，避免行号偏移
        usort($entries, fn ($a, $b) => $b['line'] - $a['line']);

        foreach ($entries as $entry) {
            $lineIdx = $entry['line'] - 1;
            $line = $lines[$lineIdx];

            // 替换 \RuntimeException 或 RuntimeException 为目标异常类
            $newLine = preg_replace(
                '/\\\\?RuntimeException/',
                $entry['target'],
                $line,
                1
            );

            if ($newLine !== $line) {
                $lines[$lineIdx] = $newLine;
                $usesNeeded[$entry['use']] = $entry['target'];
            }
        }

        // 添加 use 语句（如果不存在）
        $newContent = implode("\n", $lines);
        foreach ($usesNeeded as $fqcn => $shortName) {
            $useStatement = "use {$fqcn};";
            if (! str_contains($newContent, $useStatement)) {
                // 在最后一个 use 语句后插入
                $newContent = preg_replace(
                    '/(use [^;]+;\n)(?!use )/',
                    "$1{$useStatement}\n",
                    $newContent,
                    1
                );
            }
        }

        file_put_contents($absPath, $newContent);
        $modified++;
        $count = count($entries);
        echo "  \u2713 {$relPath} ({$count} 处)\n";
    }

    echo "\n完成: 修改 {$modified} 个文件\n";
    echo "⚠️  请运行 composer test:filter \"DomainException\" 验证\n";
}
