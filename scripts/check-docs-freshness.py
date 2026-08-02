#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
docs/manifest.json 文档新鲜度检查

用法:
  python3 scripts/check-docs-freshness.py              # 检查所有文档
  python3 scripts/check-docs-freshness.py --stale-only  # 只显示过期文档
  python3 scripts/check-docs-freshness.py --json         # JSON 输出
  python3 scripts/check-docs-freshness.py --update       # 更新 last_synced 为今天

原理:
  对 manifest.json 中每个文档，检查其 tracked_signals 涉及的文件
  在 last_synced 日期之后是否有变更。如果有 → 文档可能过期。

退出码:
  0 = 所有文档新鲜
  1 = 有过期文档
  2 = manifest.json 读取失败
"""

import json
import subprocess
import sys
from datetime import datetime, date
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
MANIFEST = ROOT / "docs" / "manifest.json"


def load_manifest() -> dict:
    try:
        return json.loads(MANIFEST.read_text(encoding="utf-8"))
    except (FileNotFoundError, json.JSONDecodeError) as e:
        print(f"错误: 无法读取 {MANIFEST}: {e}", file=sys.stderr)
        sys.exit(2)


def git_changed_since(since_date: str, patterns: list[str]) -> list[str]:
    """检查 git 中在 since_date 之后变更的文件（匹配 patterns）"""
    changed = set()
    for pattern in patterns:
        try:
            result = subprocess.run(
                ["git", "log", "--name-only", "--pretty=format:", "--diff-filter=ACMR",
                 f"--since={since_date}", "--", pattern],
                capture_output=True, text=True, cwd=ROOT, timeout=10,
            )
            for line in result.stdout.splitlines():
                line = line.strip()
                if line:
                    changed.add(line)
        except (subprocess.TimeoutExpired, FileNotFoundError):
            pass
    return sorted(changed)


def count_modules() -> int:
    """统计 src/Modules/ 下的模块数（排除 Contracts）"""
    modules_dir = ROOT / "src" / "Modules"
    if not modules_dir.exists():
        return 0
    return sum(1 for d in modules_dir.iterdir()
               if d.is_dir() and d.name != "Contracts")


def count_contracts() -> int:
    """统计 src/Contracts/ 下的接口数"""
    contracts_dir = ROOT / "src" / "Contracts"
    if not contracts_dir.exists():
        return 0
    return sum(1 for f in contracts_dir.iterdir() if f.suffix == ".php")


def check_stats_freshness(stats: dict) -> list[str]:
    """检查 manifest.stats 中的计数是否与实际一致"""
    issues = []
    actual_modules = count_modules()
    actual_contracts = count_contracts()

    if stats.get("modules") != actual_modules:
        issues.append(f"stats.modules={stats.get('modules')}, 实际={actual_modules}")
    if stats.get("contracts") != actual_contracts:
        issues.append(f"stats.contracts={stats.get('contracts')}, 实际={actual_contracts}")

    return issues


def main():
    args = sys.argv[1:]
    stale_only = "--stale-only" in args
    json_output = "--json" in args
    update_mode = "--update" in args

    manifest = load_manifest()
    today = date.today().isoformat()
    results = []

    # 检查 stats 一致性
    stats_issues = check_stats_freshness(manifest.get("stats", {}))
    if stats_issues and not json_output:
        for issue in stats_issues:
            print(f"\033[33m[统计不一致]\033[0m {issue}")

    for entry in manifest["files"]:
        path = entry["path"]
        scope = entry["scope"]
        last_synced = entry.get("last_synced", "1970-01-01")
        signals = entry.get("tracked_signals", [])

        if not signals:
            results.append({
                "path": path, "scope": scope,
                "status": "no_signals", "last_synced": last_synced,
                "changed_files": [],
            })
            continue

        changed = git_changed_since(last_synced, signals)
        is_stale = len(changed) > 0

        if update_mode and is_stale:
            # 更新 last_synced 为今天
            entry["last_synced"] = today
            last_synced = today

        results.append({
            "path": path, "scope": scope,
            "status": "stale" if is_stale else "fresh",
            "last_synced": last_synced,
            "changed_files": changed[:10],  # 最多显示 10 个
            "changed_count": len(changed),
        })

    if update_mode:
        manifest["generated_at"] = today
        MANIFEST.write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + "\n",
                            encoding="utf-8")
        print(f"\033[32m[manifest.json 已更新]\033[0m 所有过期文档的 last_synced 已更新为 {today}")

    if json_output:
        output = {
            "version": manifest["version"],
            "checked_at": datetime.now().isoformat(),
            "stats_consistent": len(stats_issues) == 0,
            "stats_issues": stats_issues,
            "total": len(results),
            "fresh": sum(1 for r in results if r["status"] == "fresh"),
            "stale": sum(1 for r in results if r["status"] == "stale"),
            "no_signals": sum(1 for r in results if r["status"] == "no_signals"),
            "files": results,
        }
        print(json.dumps(output, indent=2, ensure_ascii=False))
        stale_count = output["stale"]
        sys.exit(1 if stale_count > 0 else 0)

    # 文本输出
    stale_count = 0
    for r in results:
        if stale_only and r["status"] != "stale":
            continue

        if r["status"] == "stale":
            stale_count += 1
            print(f"\033[31m[过期]\033[0m {r['path']} (同步于 {r['last_synced']}, {r['changed_count']} 个信号文件变更)")
            for f in r["changed_files"][:5]:
                print(f"         → {f}")
            if r["changed_count"] > 5:
                print(f"         ... 还有 {r['changed_count'] - 5} 个文件")
        elif r["status"] == "fresh":
            if not stale_only:
                print(f"\033[32m[新鲜]\033[0m {r['path']} (同步于 {r['last_synced']})")
        elif r["status"] == "no_signals":
            if not stale_only:
                print(f"\033[33m[无信号]\033[0m {r['path']} (无 tracked_signals)")

    print(f"\n--- 总计: {len(results)} 个文档, {stale_count} 个过期 ---")
    if stats_issues:
        print(f"    统计不一致: {len(stats_issues)} 项")

    sys.exit(1 if stale_count > 0 else 0)


if __name__ == "__main__":
    main()
