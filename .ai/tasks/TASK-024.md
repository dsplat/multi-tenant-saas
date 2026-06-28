# TASK-024: 租户成本与资源追踪

**Sprint:** sprint-006  
**状态:** READY  
**依赖:** TASK-023（MetricsService）、TASK-014（AiUsageService）  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现租户级成本分摊和资源用量追踪。

> **⚠ 跨版本依赖**: 依赖 TASK-014 (v0.5.0) 的 AiUsageService。未通过则阻塞。

---

## 范围

**只允许修改：**
- `src/Services/CostService.php`（新建）
- `src/Services/ResourceService.php`（新建）
- `src/Models/CostAllocation.php`（新建）
- `database/migrations/` 下新增 cost_allocations 迁移
- `config/tenancy.php`（追加成本追踪配置）
- `lang/zh_CN/common.php`、`lang/en/common.php`（追加翻译 key）
- `tests/CostServiceTest.php`（新建）
- `tests/ResourceServiceTest.php`（新建）
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

### CostService

1. 基础设施成本分摊（计算/存储/带宽）
2. AI 用量成本
3. 第三方服务成本
4. 租户级盈亏分析
5. 成本趋势预测
6. 月度成本报表

### ResourceService

1. 数据库连接数
2. 队列积压量
3. 缓存命中率
4. 存储用量
5. 每个租户的资源占用比例
6. 资源告警阈值

### 数据模型

`cost_allocations` 表: 租户ID、成本类型、金额、周期、分摊依据

### 集成

与 TASK-014 的 AiUsageService 联动，AI 成本自动归入租户成本

---

## 验收标准

- [ ] 成本分摊正常
- [ ] AI 用量成本归入正常
- [ ] 租户级盈亏分析正常
- [ ] 月度成本报表正常
- [ ] 资源用量监控正常
- [ ] 资源告警正常
- [ ] TestCase 追加新表 schema，phpunit 全绿
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- CostAllocation 模型 use HasTenantScope
- 成本数据按月聚合
- 资源监控使用现有 CacheService 和 QueueService 获取数据
- 告警通过现有 Notification 系统发送

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
