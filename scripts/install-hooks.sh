#!/bin/sh
# 启用 multi_tenant_saas 架构守卫 git 钩子（仅需在克隆后执行一次）
# 原理：将 core.hooksPath 指向随仓库版本化的 .githooks/ 目录，团队共享同一套钩子。
set -e
cd "$(git rev-parse --show-toplevel)"
git config core.hooksPath .githooks
chmod +x .githooks/pre-commit scripts/architecture_guard.py 2>/dev/null || true
echo "[OK] 架构守卫钩子已启用（core.hooksPath=.githooks）。"
echo "     提交时将自动检查：大小写冲突 / 模块大驼峰命名 / PSR-4 命名空间。"
echo "     紧急绕过：git commit --no-verify"
