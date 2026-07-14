# Split Pull - 拉取模块更新

下游项目使用此 skill 管理框架和模块依赖。

## 使用场景

- 首次安装框架和模块
- 更新框架到最新版本
- 添加新模块
- 检查模块版本状态

## 首次安装

### 1. 安装核心框架

```bash
composer create-project dsplat/multi-tenant-saas my-app
```

### 2. 配置 VCS 仓库

在下游项目的 `composer.json` 添加 `repositories`：

```json
{
    "repositories": [
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-auth"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-billing"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-user"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-infrastructure"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-notification"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-storage"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-operator"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-ai"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-api-token"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-conversation"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-coupon"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-developer-portal"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-domain"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-event"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-form"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-logging"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-lottery"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-monitoring"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-payment"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-platform"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-plugin"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-sms"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-ssl"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-voting"},
        {"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-workflow"}
    ]
}
```

### 3. 安装模块

```bash
# 安装单个模块
composer require dsplat/multi-tenant-saas-module-auth

# 安装多个模块
composer require \
    dsplat/multi-tenant-saas-module-auth \
    dsplat/multi-tenant-saas-module-billing \
    dsplat/multi-tenant-saas-module-user
```

## 更新模块

### 更新所有依赖

```bash
composer update
```

### 更新特定模块

```bash
composer update dsplat/multi-tenant-saas-module-auth
```

### 更新核心框架

```bash
composer update dsplat/multi-tenant-saas
```

## 添加新模块

当框架发布新模块时：

### 1. 添加 VCS 仓库

在 `composer.json` 的 `repositories` 中添加：

```json
{"type": "vcs", "url": "https://github.com/dsplat/multi-tenant-saas-module-新模块名"}
```

### 2. 安装模块

```bash
composer require dsplat/multi-tenant-saas-module-新模块名
```

## 可用模块清单

| 包名 | 说明 |
|------|------|
| dsplat/multi-tenant-saas | 核心框架 |
| dsplat/multi-tenant-saas-module-ai | AI 功能 |
| dsplat/multi-tenant-saas-module-api-token | API Token |
| dsplat/multi-tenant-saas-module-auth | 认证授权 |
| dsplat/multi-tenant-saas-module-billing | 计费 |
| dsplat/multi-tenant-saas-module-conversation | 会话 |
| dsplat/multi-tenant-saas-module-coupon | 优惠券 |
| dsplat/multi-tenant-saas-module-developer-portal | 开发者门户 |
| dsplat/multi-tenant-saas-module-domain | 域名 |
| dsplat/multi-tenant-saas-module-event | 事件 |
| dsplat/multi-tenant-saas-module-form | 表单 |
| dsplat/multi-tenant-saas-module-infrastructure | 基础设施 |
| dsplat/multi-tenant-saas-module-logging | 日志 |
| dsplat/multi-tenant-saas-module-lottery | 抽奖 |
| dsplat/multi-tenant-saas-module-monitoring | 监控 |
| dsplat/multi-tenant-saas-module-notification | 通知 |
| dsplat/multi-tenant-saas-module-operator | 运营人员 |
| dsplat/multi-tenant-saas-module-payment | 支付 |
| dsplat/multi-tenant-saas-module-platform | 平台 |
| dsplat/multi-tenant-saas-module-plugin | 插件 |
| dsplat/multi-tenant-saas-module-sms | 短信 |
| dsplat/multi-tenant-saas-module-ssl | SSL |
| dsplat/multi-tenant-saas-module-storage | 存储 |
| dsplat/multi-tenant-saas-module-user | 用户 |
| dsplat/multi-tenant-saas-module-voting | 投票 |
| dsplat/multi-tenant-saas-module-workflow | 工作流 |

## 故障排除

### 依赖冲突

```bash
# 清除缓存重新安装
composer clear-cache
composer update
```

### 版本锁定

```bash
# 查看当前安装的版本
composer show dsplat/*
```

### VCS 模式问题

确保 `composer.json` 中：
- `repositories` 配置正确
- 没有 `path` 类型的仓库（本地开发除外）
- 版本约束使用 `*` 或 `dev-main`
