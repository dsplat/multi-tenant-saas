<?php

/**
 * 批量给 Eloquent 模型注入 SerializesFriendlyDates trait（日期序列化统一 Y-m-d H:i:s）
 *
 * 用法：php scripts/patch_date_serialization.php <目录1> [目录2 ...]
 * 规则：
 *  - 匹配 `extends Model` / `extends Authenticatable` 的类文件
 *  - 顶部 use 块追加 import（按字母序近似：追加到最后一个顶层 use 行之后）
 *  - 类体开头插入 `use SerializesFriendlyDates;`（紧随类声明的 `{` 之后）
 *  - 幂等：已含 SerializesFriendlyDates 的文件跳过
 */

$dirs = array_slice($argv, 1);
if (empty($dirs)) {
    fwrite(STDERR, "用法: php scripts/patch_date_serialization.php <目录> [...]\n");
    exit(1);
}

$importLine = 'use MultiTenantSaas\Concerns\SerializesFriendlyDates;';
$traitLine = 'use SerializesFriendlyDates;';
$patched = 0;
$skipped = 0;

foreach ($dirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $src = file_get_contents($path);

        if (strpos($src, 'SerializesFriendlyDates') !== false) {
            $skipped++;
            continue;
        }
        if (! preg_match('/^\s*(?:abstract\s+)?class\s+\w+\s+extends\s+(Model|Authenticatable)\b/m', $src)) {
            continue;
        }

        $lines = explode("\n", $src);

        // 1) 定位类声明行
        $classIdx = null;
        foreach ($lines as $i => $l) {
            if (preg_match('/^\s*(?:abstract\s+)?class\s+\w+\s+extends\s+(Model|Authenticatable)\b/', $l)) {
                $classIdx = $i;
                break;
            }
        }
        if ($classIdx === null) {
            continue;
        }

        // 2) 顶部 import：最后一个顶层 use 行（类声明之前）
        $lastTopUse = null;
        for ($i = 0; $i < $classIdx; $i++) {
            if (preg_match('/^use\s+[\w\\\\]+;$/', trim($lines[$i]))) {
                $lastTopUse = $i;
            }
        }
        if ($lastTopUse === null) {
            fwrite(STDERR, "SKIP(无顶部use): $path\n");
            continue;
        }
        array_splice($lines, $lastTopUse + 1, 0, [$importLine]);
        $classIdx++; // 插入一行后类声明行号后移

        // 3) 类体插入 trait use：找类声明后第一个含 `{` 的行
        $braceIdx = null;
        for ($i = $classIdx; $i < count($lines); $i++) {
            if (strpos($lines[$i], '{') !== false) {
                $braceIdx = $i;
                break;
            }
        }
        if ($braceIdx === null) {
            fwrite(STDERR, "SKIP(未找到类体左括号): $path\n");
            continue;
        }
        array_splice($lines, $braceIdx + 1, 0, ['    '.$traitLine]);

        file_put_contents($path, implode("\n", $lines));
        $patched++;
        echo "patched: $path\n";
    }
}

echo "完成：patched=$patched skipped=$skipped\n";
