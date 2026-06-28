# TASK-022: 功能开关系统

**Sprint:** sprint-005  
**状态:** READY  
**依赖:** 无  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现功能开关系统，支持全局/租户/用户级开关、灰度发布和 A/B 测试。

---

## 范围

**只允许修改：**
- `src/Services/FeatureFlagService.php`（新建）
- `src/Models/FeatureFlag.php`（新建）
- `src/Middleware/CheckFeatureFlag.php`（新建）
- `database/migrations/` 下新增 feature_flags 迁移
- `config/tenancy.php`（追加功能开关配置）
- `lang/zh_CN/common.php`、`lang/en/common.php`（追加翻译 key）
- `tests/FeatureFlagServiceTest.php`（新建）
- `tests/TestCase.php`（追加新表 schema）

**禁止修改：**
- `.ai/scripts/` 下所有文件
- `.ai/prompts/` 下所有文件
- `app/` 应用层代码
- `resources/` 前端资源
- `public/` 公共入口
- `src/` 下除上述允许文件外的其他文件

---

## 具体内容

### FeatureFlagService

1. 全局开关
2. 租户级开关
3. 用户级开关
4. 灰度发布（百分比滚动）
5. A/B 测试分组
6. 开关依赖关系
7. 开关历史

### CheckFeatureFlag 中间件

路由级功能开关检查，未启用返回 404

### 数据模型

`feature_flags` 表: 名称、描述、范围(global/tenant/user)、启用条件(JSON)、灰度比例、状态

### 预置开关

ai_text、ai_image、ai_video、beta_features、new_dashboard

---

## 验收标准

- [ ] 全局/租户/用户级开关正常
- [ ] 灰度发布正常（百分比滚动）
- [ ] A/B 测试分组正常
- [ ] 开关依赖关系正常
- [ ] CheckFeatureFlag 中间件正常
- [ ] 预置开关已创建
- [ ] TestCase 追加新表 schema，phpunit 全绿
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- FeatureFlagService 注册为 singleton
- 灰度发布使用哈希算法（如 tenant_id hash % 100 < percentage）
- 开关缓存使用 Redis（如有），降级使用数组缓存
- CheckFeatureFlag 中间件通过路由参数指定开关名

---

## 全局规范声明

> **⚠ 严格遵守全局约束 — 此部分适用于本任务的所有子任务（a/b/c/d...），无例外**

### 1. 禁止修改的文件

- **`.ai/scripts/` 目录下任何文件**（loop-run.sh、parallel-run.sh、loop-watch.sh、plan-task.sh、lib.sh 等）
- **`.ai/prompts/` 目录下任何文件**（dev-prompt.md、review-prompt.md、plan-prompt.md 等）
- 如 AI 在执行过程中发现需要修改上述文件，应**停止并向用户报告**，而不是自行修改

### 2. 编码规范

- 遵循 **PSR-12** 规范，使用 **Laravel 最佳实践**
- 所有 Controller 必须使用 **API Resource** 返回数据，禁止直接返回模型或数组
- 敏感字段（password/token/secret/key）**永不返回**，手机号脱敏
- 所有方法参数必须有**类型声明**，所有方法必须有**返回值类型声明**
- 使用 PHP 8.1+ 特性（枚举、只读属性等）
- 使用中文注释 + PHPDoc

### 3. 多语言规范

- 使用 `trans()` / `__()` 函数实现多语言，**禁止硬编码中文字符串**
- 新增翻译 key 必须同时添加到 `lang/zh_CN/` 和 `lang/en/` 两个目录

### 4. 数据库规范

- 迁移文件命名接续现有序号（查看 `database/migrations/` 最大序号后 +1）
- 新建模型 use `HasTenantScope` trait 实现租户隔离
- Service 类通过 `TenancyServiceProvider` 注册为 singleton

### 5. 响应格式

- 统一用 `ApiResponse::success()` 和 `ApiResponse::error()`
- 错误码标准化，HTTP 状态码正确

### 6. 测试规范

- 每个新建 Service 必须有对应的 Test 文件
- 测试继承 `tests/TestCase.php`，如需新表 schema 在 TestCase.php 中追加
- `php vendor/bin/phpunit` 全绿（预存在的失败除外，但不得新增失败）
