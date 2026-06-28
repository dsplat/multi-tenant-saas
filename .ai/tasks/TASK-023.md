# TASK-023: 实时指标与 SLA 监控

**Sprint:** sprint-006  
**状态:** READY  
**依赖:** 无  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现实时指标采集和 SLA 监控，具备系统性能可视化和告警能力。

---

## 范围

**只允许修改：**
- `src/Services/MetricsService.php`（新建）
- `src/Services/SlaService.php`（新建）
- `src/Models/MetricsSnapshot.php`（新建）
- `src/Models/SlaEvent.php`（新建）
- `src/Console/Commands/CollectMetrics.php`（新建）
- `database/migrations/` 下新增 metrics_snapshots、sla_events 迁移
- `config/health.php`（追加 SLA 配置）
- `lang/zh_CN/common.php`、`lang/en/common.php`（追加翻译 key）
- `tests/MetricsServiceTest.php`（新建）
- `tests/SlaServiceTest.php`（新建）
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

### MetricsService

1. 请求量（QPS/RPM）
2. P50/P95/P99 延迟
3. 错误率
4. 活跃租户数/用户数
5. API 端点分布
6. 时序数据存储和聚合

### SlaService

1. 可用性计算（uptime/total * 100）
2. SLA 达标率（月/季/年）
3. 违约事件记录
4. 多级 SLA（99.9%/99.95%/99.99%）
5. 告警触发

### CollectMetrics 命令

每分钟采集指标快照，聚合到小时/天/月粒度

### 数据模型

1. `metrics_snapshots` 表: 时间戳、指标名、值、维度(租户/端点/区域)、粒度
2. `sla_events` 表: 事件类型(downtime/degradation)、开始时间、结束时间、受影响范围、严重级别

---

## 验收标准

- [ ] 指标采集正常（QPS/延迟/错误率）
- [ ] P50/P95/P99 延迟计算正确
- [ ] SLA 可用性计算正确
- [ ] SLA 违约事件记录正常
- [ ] 告警触发正常
- [ ] CollectMetrics 命令正常执行
- [ ] TestCase 追加新表 schema，phpunit 全绿
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- MetricsService 注册为 singleton
- 指标存储使用 MySQL（生产可切换到 InfluxDB/Prometheus）
- CollectMetrics 命令注册在 src/Console/Commands/
- 延迟百分位计算使用排序后取百分位索引

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
