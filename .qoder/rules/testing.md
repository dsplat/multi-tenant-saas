---
trigger: always_on
alwaysApply: true
---

# 测试执行规则（禁止无脑全量）

## 核心原则：只跑受影响的测试

修改代码后，**禁止**直接 `composer test` 全量执行（178+ 测试文件）。必须根据改动范围选择最小测试集。

## 执行策略（按优先级）

### 1. 精准文件（首选）

改了哪个模块/服务，就跑对应测试文件：

```bash
# 单个文件
php artisan test tests/AiOptionalTest.php

# 多个相关文件
php artisan test tests/AiConfigServiceTest.php tests/AiUsageServiceTest.php
```

### 2. 关键词过滤（改动涉及多文件时）

```bash
composer test:filter AiOptional
composer test:filter "Agent|Conversation"
composer test:filter "Coupon"
```

`test:filter` 底层是 `artisan test --parallel --filter`，并行 + 正则匹配测试类名/方法名。

### 3. 目录级（子目录测试）

```bash
php artisan test tests/Conversation/
php artisan test tests/Schema/
php artisan test tests/EnterpriseWechat/
```

### 4. 全量（仅在以下情况）

- 用户**明确要求**全量
- 修改了 `composer.json`、`phpunit.xml.dist`、`TenancyServiceProvider`、全局中间件等**基础设施**
- 修改了 `src/Contracts/` 中被广泛实现的接口
- 发版前最终验证

## 改动→测试映射速查

| 改动位置 | 应跑测试 |
|---------|---------|
| `src/Modules/Ai/Services/*` | `tests/Ai*Test.php`、`tests/Agent*Test.php` |
| `src/Modules/Ai/Http/Controllers/*` | `tests/Agent*ControllerTest.php`、`tests/Mcp*Test.php` |
| `src/Modules/Ai/Mcp/*` | `tests/McpToolRegistryTest.php`、`tests/McpServerControllerTest.php` |
| `src/Modules/Conversation/*` | `tests/Conversation/` 目录 |
| `src/Modules/EnterpriseWechat/*` | `tests/EnterpriseWechat/` 目录 |
| `src/Modules/WechatMiniProgram/*` | `tests/WechatMiniProgram/` 目录 |
| `src/Modules/WechatOfficial/*` | `tests/WechatOfficial/` 目录 |
| `src/Modules/<其他>/` | `tests/Schema/` + `tests/` 根下同名 `*Test.php` |
| `src/Http/Controllers/*` | `tests/*ControllerTest.php` |
| `src/Services/*` | `tests/` 根下同名 `*ServiceTest.php` |
| `src/Jobs/*`、`src/Events/*` | `tests/EventBusServiceTest.php` + 相关模块测试 |
| `src/Contracts/*` | 全量（接口变更影响面广） |
| `src/Isolation/*`、`src/Scopes/*` | `tests/DataIsolationTest.php`、`tests/IsolationServiceTest.php` |
| `config/*`、`database/migrations/*` | `tests/Schema/` 目录 |

## 禁止事项

- ❌ 改一个 Service 就跑 `composer test`（178+ 文件，浪费数分钟）
- ❌ 用 `vendor/bin/phpunit` 绕过 artisan（丢失 parallel 和 env 配置）
- ❌ 忽略 `--parallel` 标志（composer test 已内置并行，手动调用时也应加）

## 补充

- phpunit 已配置 `executionOrder="defects"` + `.phpunit.cache`：上次失败的测试会优先执行。
- 新建测试文件后，首次单文件跑确认通过即可，无需全量验证。
- 测试环境使用 sqlite `:memory:`，无外部依赖，单文件执行通常 < 3 秒。
- 本项目 tests/ 为**扁平结构**（非 Unit/Feature 分目录），直接按文件名定位。
