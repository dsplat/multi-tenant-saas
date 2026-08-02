#!/usr/bin/env python3
# -*- coding: utf-8 -*-
r"""
multi_tenant_saas 框架架构守卫（pre-commit 钩子核心逻辑）

阻断式检查（违规 exit 1，可用 git commit --no-verify 紧急绕过）：
  1. 大小写冲突：同一目录内仅大小写不同的条目（macOS 隐身、Linux 生产爆炸）
  2. 模块目录命名：src/Modules/<Name> 必须为大驼峰（PascalCase）
  3. PSR-4 一致性：PHP 文件的 namespace 声明必须匹配其文件路径
     （MultiTenantSaas\ → src/，App\ → app/，Database\Factories\ → database/factories/，
       Database\Seeders\ → database/seeders/）

框架为拆分包（部署为 vendor/dsplat/multi-tenant-saas），模块结构较松散，
故不设"散落目录"边界检查；聚焦大小写与命名空间一致性这两类机器可判的硬伤。
"""

import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# PSR-4 映射（路径前缀 → 命名空间前缀），按路径长度降序匹配（最长前缀优先）
PSR4_MAP = [
    ("database/factories/", "Database\\Factories"),
    ("database/seeders/", "Database\\Seeders"),
    ("src/", "MultiTenantSaas"),
    ("app/", "App"),
]
PSR4_MAP.sort(key=lambda kv: len(kv[0]), reverse=True)

errors = []
warnings = []


def staged_files(diff_filter: str):
    out = subprocess.run(
        ["git", "diff", "--cached", "--name-only", "--diff-filter=" + diff_filter],
        capture_output=True, text=True, cwd=ROOT,
    )
    return [ln for ln in out.stdout.splitlines() if ln.strip()]


# ---------------------------------------------------------------------------
# 检查 1：大小写冲突
# ---------------------------------------------------------------------------
def check_case_collisions():
    seen = {}
    for f in staged_files("ACMR"):
        p = Path(f)
        for parent in p.parents:
            if parent == Path("."):
                break
            key = str(parent).lower()
            real = str(parent)
            if key in seen and seen[key] != real:
                errors.append(
                    f"大小写冲突：目录 '{real}' 与 '{seen[key]}' 仅大小写不同"
                    f"（macOS 不敏感可共存，Linux 生产将冲突/丢失）"
                )
                break
            seen[key] = real
    # 同一目录内文件名仅大小写不同
    dir_files = {}
    for f in staged_files("ACMR"):
        p = Path(f)
        dir_files.setdefault(str(p.parent).lower(), []).append(p.name)
    for _, names in dir_files.items():
        low = {}
        for n in names:
            if n.lower() in low and low[n.lower()] != n:
                errors.append(f"大小写冲突：同目录文件 '{n}' 与 '{low[n.lower()]}' 仅大小写不同")
            low[n.lower()] = n


# ---------------------------------------------------------------------------
# 检查 2：模块目录大驼峰
# ---------------------------------------------------------------------------
def check_module_pascalcase():
    pat = re.compile(r"^src/Modules/([^/]+)/")
    flagged = set()
    for f in staged_files("ACMR"):
        m = pat.match(f)
        if not m:
            continue
        name = m.group(1)
        if name in flagged:
            continue
        if not (name[0].isalpha() and name[0].isupper() and not name.islower()):
            errors.append(
                f"模块目录命名违规：src/Modules/{name} 须为大驼峰（如 {name.capitalize()}）。"
                f"拆分包部署到 Linux 生产时大小写须精确。"
            )
            flagged.add(name)


# ---------------------------------------------------------------------------
# 检查 3：PSR-4 命名空间 ↔ 路径
# ---------------------------------------------------------------------------
def expected_namespace(rel_path: str):
    for prefix, ns in PSR4_MAP:
        if rel_path.startswith(prefix):
            sub = rel_path[len(prefix):]
            parts = sub.split("/")
            if len(parts) < 2:
                return ns
            return ns + "\\" + "\\".join(parts[:-1])
    return None


def check_psr4():
    ns_re = re.compile(r"^\s*namespace\s+([^;\s]+)\s*;", re.MULTILINE)
    for f in staged_files("ACMR"):
        if not f.endswith(".php"):
            continue
        expected = expected_namespace(f)
        if not expected:
            continue  # 不在 PSR-4 映射内（如 database/migrations、stubs），跳过
        fp = ROOT / f
        if not fp.exists():
            continue
        try:
            text = fp.read_text(encoding="utf-8")
        except Exception:
            continue
        m = ns_re.search(text)
        if not m:
            continue  # 无命名空间（迁移/脚本），跳过
        declared = m.group(1).rstrip("\\")
        if declared != expected:
            errors.append(
                f"PSR-4 命名空间不匹配：{f} 声明 '{declared}'，按路径应为 '{expected}'"
            )


# ---------------------------------------------------------------------------
# 检查 4：禁止新增 throw new RuntimeException（应使用 DomainException 体系）
# ---------------------------------------------------------------------------
def check_no_runtime_exception():
    """src/ 下新增的 PHP 行不得包含 throw new \\RuntimeException。"""
    re_throw = re.compile(r'throw\s+new\s+\\?RuntimeException')
    # 只检查新增的行（git diff --cached -U0 +行）
    out = subprocess.run(
        ["git", "diff", "--cached", "-U0", "--diff-filter=ACMR", "--", "src/"],
        capture_output=True, text=True, cwd=ROOT,
    )
    current_file = ""
    for line in out.stdout.splitlines():
        if line.startswith("+++ b/"):
            current_file = line[6:]
        elif line.startswith("+") and not line.startswith("+++"):
            if current_file.endswith(".php") and re_throw.search(line):
                errors.append(
                    f"RuntimeException 禁用：{current_file} 中新增了 throw new RuntimeException\n"
                    f"          → 请使用 DomainException/NotFoundException/ServiceUnavailableException 等领域异常\n"
                    f"          → 参考: src/Exceptions/ 目录下的 11 个异常类"
                )
                break  # 每文件报一次即可


# ---------------------------------------------------------------------------
# 检查 5：AI KB 索引新鲜度（警告，不阻断）
# ---------------------------------------------------------------------------
def check_kb_index_freshness():
    """routes.ts / 工具注册 / module-loader 变更时，提醒重新生成 AI KB 索引。"""
    added = staged_files("A") + staged_files("M")
    trigger_patterns = [
        "resources/console/routes.ts",
        "AiServiceProvider.php",
        "AgentService.php",
        "module-loader.ts",
        "Services/SystemKb/",
    ]
    triggered = [f for f in added if any(p in f for p in trigger_patterns)]
    if triggered:
        warnings.append(
            f"AI KB 索引可能过期（改动: {', '.join(triggered[:3])}）。"
            f"下游项目部署前请执行: php artisan secretary:kb:index"
        )


def main():
    check_case_collisions()
    check_module_pascalcase()
    check_psr4()
    check_no_runtime_exception()
    check_kb_index_freshness()

    for w in warnings:
        print(f"\033[33m[架构守卫 WARN]\033[0m {w}")

    if errors:
        print("\033[31m[架构守卫] 检测到架构违规，提交已拦截：\033[0m")
        for e in errors:
            print(f"  \033[31m✗\033[0m {e}")
        print("\n  修复后重新提交；紧急情况可用 git commit --no-verify 绕过（不推荐）。")
        return 1

    print("\033[32m[架构守卫] 通过：大小写 / 模块命名 / PSR-4 均合规。\033[0m")
    return 0


if __name__ == "__main__":
    sys.exit(main())
