---
name: dsplat-release
description: 发版流程：更新版本号、创建 tag、触发 split workflow、验证模块同步
triggers:
  - /dsplat-release
  - release
  - 发版
  - 打 tag
  - 发布新版本
---

# dsplat 发版流程

## 前置条件

- 所有测试通过：`php vendor/bin/phpunit`
- 代码风格检查通过：`vendor/bin/pint --test`
- 工作区干净：`git status`

---

## 发版步骤

### 1. 确定版本号

```bash
# 查看当前 tag
git tag --sort=-v:refname | head -5

# 版本号规则：
# - 主版本：不兼容的 API 变更
# - 次版本：新功能、新模块
# - 补丁：bug 修复
```

### 2. 更新版本号（如需要）

```bash
# 更新 composer.json 版本
# 更新 CHANGELOG.md（如有）
```

### 3. 提交所有更改

```bash
git add -A
git commit -m "release: vX.Y.Z"
git push origin main
```

### 4. 创建并推送 tag

```bash
# 创建 tag
git tag vX.Y.Z

# 推送 tag
git push origin vX.Y.Z
```

### 5. 验证 CI

```bash
# 检查 CI 状态
gh run list --limit 5

# 等待完成
sleep 60 && gh run list --limit 3
```

### 6. 验证 Split Workflow

```bash
# 检查 split workflow
gh run list --workflow=split.yml --limit 3

# 如果没有自动触发，手动触发
gh workflow run split.yml --ref main

# 等待完成并验证 26/26 成功
sleep 180 && gh run view <run-id> --json jobs -q '.jobs | length'
```

### 7. 验证模块仓库

```bash
# 检查核心仓库
gh api repos/dsplat/multi-tenant-saas/commits --jq '.[0].commit.message'

# 检查几个模块仓库
for repo in auth billing user operator; do
  echo -n "multi-tenant-saas-module-$repo: "
  gh api repos/dsplat/multi-tenant-saas-module-$repo/commits --jq '.[0].commit.message'
done
```

### 8. Packagist 检查（如已注册）

```bash
# 检查 Packagist 状态
curl -s "https://packagist.org/packages/dsplat/multi-tenant-saas.json" | jq '.package.versions | keys | last'
```

---

## 新模块发布

如果是新增模块，还需要：

### 1. 注册 Packagist

访问 https://packagist.org/packages/submit，提交：
- 包名：`dsplat/multi-tenant-saas-module-xxx`
- 仓库 URL：`https://github.com/dsplat/multi-tenant-saas-module-xxx`

### 2. 通知下游更新

提醒下游项目在 `composer.json` 添加 VCS 仓库：

```json
{"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-xxx"}
```

---

## 故障排除

### Split Workflow 失败

```bash
# 查看失败原因
gh run view <run-id> | grep -E "✓|X"

# 常见原因：
# 1. API 限流 → 等待 1 小时
# 2. 仓库不存在 → 手动创建
# 3. 权限问题 → 检查 SPLIT_TOKEN
```

### Tag 已存在

```bash
# 删除本地 tag
git tag -d vX.Y.Z

# 删除远程 tag
git push origin :refs/tags/vX.Y.Z

# 重新创建
git tag vX.Y.Z
git push origin vX.Y.Z
```

### 下游拉取不到新版本

```bash
# 清除 composer 缓存
composer clear-cache

# 强制更新
composer update dsplat/multi-tenant-saas --ignore-platform-reqs -W
```
