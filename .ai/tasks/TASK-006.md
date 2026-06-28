# TASK-006: 优惠券与试用管理

**Sprint:** sprint-002  
**状态:** READY  
**依赖:** TASK-005（Invoice/Tax 完成后才能做优惠券关联发票）  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现优惠券/折扣系统和试用期管理，使框架具备促销和试用转化能力。

---

## 范围

**允许修改：**
- `src/Services/CouponService.php`（新建）
- `src/Services/TrialService.php`（新建）
- `src/Models/Coupon.php`（新建）
- `src/Models/CouponUsage.php`（新建）
- `database/migrations/` 下新增 coupon/trial 相关迁移
- `src/Models/Tenant.php`（追加试用相关字段）
- `lang/zh_CN/subscription.php`、`lang/en/subscription.php`（新增翻译 key）
- `tests/CouponServiceTest.php`（新建）
- `tests/TrialServiceTest.php`（新建）
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

### CouponService

1. **折扣类型**: 固定金额折扣、百分比折扣
2. **使用限制**: 使用次数限制（全局+每租户）、过期日期、最低消费金额、适用套餐范围
3. **批量生成**: 批量生成优惠码（指定前缀+随机后缀）
4. **核销逻辑**: 验证可用性 → 扣减次数 → 记录使用 → 返回折扣金额
5. **查询**: 优惠券列表、使用记录、统计

### TrialService

1. **试用期配置**: 默认 14 天，可按套餐自定义
2. **到期处理**: 自动转换为付费订阅或暂停租户
3. **试用延长**: 管理员可手动延长试用期
4. **到期提醒**: 到期前 3天/1天/当天 发送通知（调用现有 Notification 系统）
5. **状态查询**: 试用状态、剩余天数、是否已延长

### 数据模型

1. `coupons` 表: 优惠码(unique)、类型(fixed/percent)、面值、最低消费、最大使用次数、已使用次数、有效期开始、有效期结束、适用套餐(JSON)、状态
2. `coupon_usages` 表: 租户ID、优惠券ID、订单ID、使用时间、折扣金额
3. Tenant 模型追加字段: `trial_ends_at`(timestamp)、`trial_extended`(boolean)、`trial_notification_sent_at`(timestamp)

### TestCase 补充

在 TestCase.php 的 `setUpDatabase()` 中追加 `coupons`、`coupon_usages` 表 schema。Tenant 表追加 `trial_ends_at`、`trial_extended`、`trial_notification_sent_at` 字段。

---

## 验收标准

- [ ] CouponService 支持固定金额和百分比两种折扣类型
- [ ] 优惠券使用限制正常（次数、过期、最低消费、适用套餐）
- [ ] 批量生成优惠码功能正常
- [ ] TrialService 试用期配置和到期处理正常
- [ ] 试用到期提醒逻辑正确（3天/1天/当天）
- [ ] Tenant 模型新字段 migration 正常执行
- [ ] TestCase 追加新表 schema，`php vendor/bin/phpunit` 全绿
- [ ] 新增翻译 key 无缺失（zh_CN 和 en 双语）

---

## 给 AI 的补充说明

- Coupon 模型 use `HasTenantScope`，但优惠券本身是系统级的（tenant_id 可为 null 表示全局可用）
- CouponUsage 模型 use `HasTenantScope`，记录每个租户的使用
- 优惠码生成: 大写字母+数字，去除易混淆字符（O/0/I/1）
- TrialService 到期提醒调用 `app/Notifications/` 下的现有通知类
- 迁移文件命名: `2026_06_27_000011_create_coupons_table.php` 等序号接续
