---
trigger: always_on
alwaysApply: true
---

# 框架部署规则

## 部署流程（唯一正确路径）

```
git push origin main
       ↓
GitHub Actions split.yml 自动触发
       ↓
所有模块拆分包（src/Modules 下各模块 + 根包，当前 37 模块 + 1 根包 = 38 个，以 split.yml matrix 为唯一事实源；module-campaign 已于 2026-08 归档）推送到 dsplat/multi-tenant-saas-module-* 的 main 分支
       ↓
【强制】本地 scrm-platform 执行 composer update dsplat/* → commit composer.lock
       ↓
scrm-platform deploy.py incremental（rsync 源码 + 服务器 composer install）
```

## 强制前置步骤（不可跳过）

框架代码变更后、后台部署前，**必须**在本地执行：

```bash
cd /Users/arthur/Devel/WorkSpaceAI/framework/scrm-platform
composer update dsplat/multi-tenant-saas dsplat/multi-tenant-saas-module-* --with-dependencies
git add composer.lock && git commit -m "chore: sync framework packages"
```

**原因**：deploy.py 通过检测 `composer.lock` 是否变化来决定服务器端是否执行 `composer install`。
若 lock 文件未更新，服务器 vendor/ 不会拉取新包，部署静默失败。

## 关键事实

- **框架没有独立的生产部署步骤**：推送即发布（split 自动完成）
- **split 工作流**：`.github/workflows/split.yml`，push 到 main 或 tag 时触发
- **拆包映射**：`src/Modules/<PascalCase>/` → `dsplat/multi-tenant-saas-module-<kebab-case>`
- **根包**：整个仓库 → `dsplat/multi-tenant-saas`（prefix 为空）
- **下游版本约束**：`dev-main as x.99.0`（composer update 即拉最新）

## 禁止事项

- ❌ 不要用 `deploy/deploy.py` 直接 rsync 到服务器（那是备用/调试路径）
- ❌ 不要在框架项目里跑 `composer install/update`（框架是源码仓库，不是应用）
- ❌ 不要手动 push split 分支（由 CI 自动完成）

## deploy/deploy.py 的定位

`deploy/deploy.py` 是**调试/热修复**工具，用于紧急情况下绕过 split 直接同步文件到服务器 vendor 目录。正常发布流程不使用它。

## 下游部署方式（scrm-platform）

scrm-platform 使用 `deploy/deploy.py incremental`：
- **项目源码**（app/, config/, routes/, composer.lock…）→ rsync 到服务器
- **vendor 依赖**（含 dsplat/* 框架包）→ 服务器端 `composer install`（lock 变化时触发）
- **服务器无 .git**，禁止在服务器上执行任何 git 命令

```bash
cd /Users/arthur/Devel/WorkSpaceAI/framework/scrm-platform
python3 deploy/deploy.py incremental --yes
```

## 验证 split 完成

```bash
# 检查 GitHub Actions 运行状态
gh run list --repo dsplat/multi-tenant-saas --workflow=split.yml --limit 1
```
