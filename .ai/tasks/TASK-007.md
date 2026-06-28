# TASK-007: 催款与按量计费

**Sprint:** sprint-002  
**状态:** READY  
**依赖:** TASK-005、TASK-006  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现付款失败催款管理(Dunning)和按量计费基座，使框架具备自动催收和用量计费能力。

---

## 范围

**允许修改：**
- `src/Services/DunningService.php`（新建）
- `src/Services/UsageService.php`（新建）
- `src/Services/PlanChangeService.php`（新建）
- `src/Models/UsageRecord.php`（新建）
- `database/migrations/` 下新增 usage_records 迁移
- `src/Models/SubscriptionPlan.php`（追加按量计费字段）
- `app/Console/Commands/ProcessSubscriptions.php`（追加催款逻辑）
- `src/Notifications/SubscriptionExpiringNotification.php`（追加催款模板）
- `lang/zh_CN/subscription.php`、`lang/en/subscription.php`（新增翻译 key）
- `tests/DunningServiceTest.php`（新建）
- `tests/UsageServiceTest.php`（新建）
- `tests/PlanChangeServiceTest.php`（新建）
- `tests/TestCase.php`（追加新表 schema）

**禁止修改：**
- `.ai/scripts/` 下所有文件
- `.ai/prompts/` 下所有文件
- `app/` 应用层代码（除 ProcessSubscriptions.php 的追加）
- `resources/` 前端资源
- `public/` 公共入口
- `src/` 下除上述允许文件外的其他文件

---

## 具体内容

### DunningService

1. **重试策略**: 付款失败后按策略重试（3次/5次/自定义），重试间隔递增（1天/3天/7天）
2. **宽限期配置**: 可配置宽限期（默认 7 天），宽限期内服务不中断
3. **到期前提醒**: 到期前 7天/3天/1天 发送提醒通知
4. **最终暂停**: 超过宽限期+重试次数后暂停租户
5. **催款事件记录**: 每次催款动作记录到审计日志

### UsageService

1. **用量记录**: 记录 API 调用次数、存储空间、AI Token 数等指标
2. **计量聚合**: 按天/小时聚合用量数据
3. **超额策略**: 硬限制(直接拒绝)、软限制+超额费、阶梯定价
4. **用量查询**: 按租户、按指标、按时间范围查询
5. **多租户速率限制**: 与现有 RateLimitService 联动，根据 SubscriptionPlan 的 rate_limit_rpm 动态调整 API 调用频率

### PlanChangeService

1. **按比例计算(Proration)**: 升级/降级时按剩余周期比例计算差价
2. **生效时机**: 立即生效 vs 周期末生效
3. **变更历史**: 记录每次套餐变更
4. **差价处理**: 补收差价或退款

### 数据模型

1. `usage_records` 表: 租户ID、指标类型(api_calls/storage/ai_tokens)、用量值、时间戳、周期标识(YYYYMM)
2. SubscriptionPlan 追加字段: `metered_price`(JSON)、`metered_unit`(string)、`overage_allowed`(boolean)、`overage_price`(decimal)、`rate_limit_rpm`(integer)

### ProcessSubscriptions.php 追加

在现有命令中追加催款逻辑：检查到期订阅 → 发送提醒 → 检查失败付款 → 执行催款策略 → 必要时暂停租户

### TestCase 补充

追加 `usage_records` 表 schema。SubscriptionPlan 表追加新字段。

---

## 验收标准

- [ ] DunningService 重试策略正常（递增间隔、宽限期、最终暂停）
- [ ] UsageService 用量记录和聚合正常
- [ ] 超额策略三种模式均可工作（硬限制/软限制/阶梯）
- [ ] PlanChangeService 按比例计算正确（升级补收、降级退款）
- [ ] 多租户速率限制与 RateLimitService 联动正常
- [ ] ProcessSubscriptions.php 催款逻辑正常执行
- [ ] TestCase 追加新表/字段，`php vendor/bin/phpunit` 全绿
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- 现有 ProcessSubscriptions.php 在 `app/Console/Commands/ProcessSubscriptions.php`，只追加不重写
- 现有 RateLimitService 在 `src/Services/RateLimitService.php`，只调用不修改
- UsageRecord 模型 use `HasTenantScope`
- SubscriptionPlan 模型已有，只追加字段不重写
- metered_price 用 JSON 存储阶梯定价规则，如 `[{"up_to": 1000, "price": 0.01}, {"up_to": null, "price": 0.005}]`
- rate_limit_rpm: 免费套餐 60，专业套餐 600，企业套餐 1000
- 迁移文件命名: `2026_06_27_000012_create_usage_records_table.php` 等序号接续
