# TASK-014: 租户 AI 配置与用量管理

**Sprint:** sprint-003  
**状态:** READY  
**依赖:** TASK-010、TASK-011、TASK-012、TASK-013、TASK-007（UsageService）  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现租户级别的 AI 能力开关、API Key 配置、用量追踪和计费集成。

> **⚠ 跨版本依赖**: 依赖 TASK-007 (v0.4.0) 的 UsageService。未通过则阻塞。

---

## 范围

**只允许修改：**
- `src/Services/AiConfigService.php`（新建）
- `src/Services/AiUsageService.php`（新建）
- `src/Models/AiTenantConfig.php`（新建）
- `src/Models/AiUsageQuota.php`（新建）
- `database/migrations/` 下新增 ai_tenant_configs、ai_usage_quotas 迁移
- `config/ai.php`（追加租户配置和配额部分）
- `src/Models/SubscriptionPlan.php`（追加 AI 配额字段）
- `lang/zh_CN/ai.php`、`lang/en/ai.php`（追加翻译 key）
- `tests/AiConfigServiceTest.php`（新建）
- `tests/AiUsageServiceTest.php`（新建）
- `tests/TestCase.php`（追加新表 schema 和 SubscriptionPlan 新字段）

**禁止修改：**
- `.ai/scripts/` 下所有文件
- `.ai/prompts/` 下所有文件
- `app/` 应用层代码
- `resources/` 前端资源
- `public/` 公共入口
- `src/` 下除上述允许文件外的其他文件

---

## 具体内容

### AiConfigService

1. 租户 AI 能力开关（text/image/video 分别）
2. 自定义 API Key 覆盖系统默认
3. 模型选择（允许租户选择模型）
4. 预算上限设置
5. 配置导入导出

### AiUsageService

1. Token 用量实时追踪
2. 图片生成次数/尺寸记录
3. 视频时长/分辨率记录
4. 按模型/按类别聚合统计
5. 用量超额告警
6. 与 UsageService 集成（统一记录到 usage_records 表）

### 数据模型

1. `ai_tenant_configs` 表: 租户ID、text_enabled、image_enabled、video_enabled、custom_api_keys(JSON)、allowed_models(JSON)、monthly_budget_limit、overage_action(block/warn/allow)
2. `ai_usage_quotas` 表: 租户ID、套餐ID、text_token_limit、image_generation_limit、video_duration_limit、period(monthly)、used_tokens、used_images、used_video_seconds
3. SubscriptionPlan 追加: ai_text_tokens、ai_image_generations、ai_video_seconds

---

## 验收标准

- [ ] 租户 AI 能力开关正常
- [ ] 自定义 API Key 覆盖正常
- [ ] Token 用量实时追踪正常
- [ ] 用量超额告警正常
- [ ] 与 UsageService 集成正常
- [ ] SubscriptionPlan AI 配额字段正常
- [ ] TestCase 追加新表/字段，phpunit 全绿
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- AiTenantConfig 和 AiUsageQuota 模型 use HasTenantScope
- AiUsageService 调用现有 UsageService 记录用量
- SubscriptionPlan 模型已存在，只追加字段
- 配额检查在 AiGatewayService 发起请求前进行
- overage_action: block=拒绝, warn=告警但允许, allow=允许并计费
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
