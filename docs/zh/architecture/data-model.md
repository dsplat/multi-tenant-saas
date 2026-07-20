# 数据模型设计

**最后更新**: 2026-07-20

---

## 核心数据表

> 以下按业务域分组列出全部 37 张表。

### tenants - 租户表

| 字段 | 类型 | 说明 |
|------|------|------|
| tenant_id | bigint | 主键，16位随机ID |
| name | varchar(100) | 租户名称 |
| slug | varchar(100) | 租户标识（唯一） |
| domain | varchar(200) | 域名 |
| custom_domain | varchar(200) | 自定义域名（唯一） |
| logo | varchar(500) | Logo URL |
| description | text | 描述 |
| subscription_plan | varchar(50) | 订阅套餐 |
| subscription_started_at | timestamp | 订阅开始时间 |
| subscription_expires_at | timestamp | 订阅过期时间 |
| total_credits | integer | 总积分 |
| used_credits | integer | 已用积分 |
| contact_name | varchar(50) | 联系人 |
| contact_email | varchar(100) | 联系邮箱 |
| contact_phone | varchar(20) | 联系电话 |
| settings | json | 设置 |
| branding | json | 品牌配置 |
| is_platform_default | boolean | 是否平台默认租户 |
| status | varchar(20) | 状态 (active/inactive/suspended) |
| ssl_uploaded_at | timestamp | SSL证书上传时间 |
| ssl_cert_expires_at | timestamp | SSL证书过期时间 |
| created_at | timestamp | 创建时间 |
| updated_at | timestamp | 更新时间 |
| deleted_at | timestamp | 删除时间（软删除） |

**索引**：
- PRIMARY: tenant_id
- UNIQUE: slug
- UNIQUE: custom_domain
- INDEX: status
- INDEX: subscription_plan

---

### users - 用户表

| 字段 | 类型 | 说明 |
|------|------|------|
| user_id | bigint | 主键，16位随机ID |
| name | varchar(255) | 用户名 |
| email | varchar(255) | 邮箱（唯一） |
| email_verified_at | timestamp | 邮箱验证时间 |
| password | varchar(255) | 密码 |
| phone | varchar(20) | 手机号（唯一） |
| role | varchar(20) | 平台角色 (super_admin/platform_user) |
| avatar | varchar(500) | 头像URL |
| is_active | boolean | 是否激活 |
| last_active_at | timestamp | 最后活跃时间 |
| remember_token | varchar(100) | 记住我Token |
| created_at | timestamp | 创建时间 |
| updated_at | timestamp | 更新时间 |
| deleted_at | timestamp | 删除时间（软删除） |

**索引**：
- PRIMARY: user_id
- UNIQUE: email
- UNIQUE: phone
- INDEX: role
- INDEX: is_active

---

### tenant_users - 租户用户关系表

| 字段 | 类型 | 说明 |
|------|------|------|
| tenant_user_id | bigint | 主键，16位随机ID |
| tenant_id | bigint | 租户ID |
| user_id | bigint | 用户ID |
| role | varchar(20) | 租户内角色 (tenant_admin/end_user) |
| credits | integer | 用户积分 |
| is_active | boolean | 是否激活 |
| joined_at | timestamp | 加入时间 |
| created_at | timestamp | 创建时间 |
| updated_at | timestamp | 更新时间 |

**索引**：
- PRIMARY: tenant_user_id
- UNIQUE: tenant_id + user_id
- INDEX: tenant_id
- INDEX: user_id
- INDEX: role

---

### tenant_settings - 租户配置表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint | 主键 |
| tenant_id | bigint | 租户ID |
| group | varchar(50) | 配置组 |
| key | varchar(100) | 配置键 |
| value | text | 配置值 |
| is_encrypted | boolean | 是否加密 |
| description | varchar(255) | 描述 |
| created_at | timestamp | 创建时间 |
| updated_at | timestamp | 更新时间 |

**索引**：
- PRIMARY: id
- UNIQUE: tenant_id + group + key
- INDEX: tenant_id
- INDEX: group

---

### credit_accounts - 积分账户表

| 字段 | 类型 | 说明 |
|------|------|------|
| credit_account_id | bigint | 主键，16位随机ID |
| tenant_id | bigint | 租户ID |
| user_id | bigint | 用户ID（可选，租户级为null） |
| balance | integer | 余额 |
| total_earned | integer | 总收入 |
| total_spent | integer | 总支出 |
| status | varchar(20) | 状态 |
| created_at | timestamp | 创建时间 |
| updated_at | timestamp | 更新时间 |

**索引**：
- PRIMARY: credit_account_id
- INDEX: tenant_id
- INDEX: user_id

---

### credit_transactions - 积分交易记录表

| 字段 | 类型 | 说明 |
|------|------|------|
| credit_transaction_id | bigint | 主键，16位随机ID |
| tenant_id | bigint | 租户ID |
| credit_account_id | bigint | 积分账户ID |
| type | varchar(20) | 类型 (recharge/consume/refund/gift) |
| amount | integer | 金额 |
| balance_after | integer | 交易后余额 |
| description | varchar(255) | 描述 |
| reference_type | varchar(100) | 关联类型 |
| reference_id | bigint | 关联ID |
| created_at | timestamp | 创建时间 |

**索引**：
- PRIMARY: credit_transaction_id
- INDEX: tenant_id
- INDEX: credit_account_id
- INDEX: type
- INDEX: created_at

---

### financial_records - 财务记录表

| 字段 | 类型 | 说明 |
|------|------|------|
| financial_record_id | bigint | 主键，16位随机ID |
| tenant_id | bigint | 租户ID |
| type | varchar(20) | 类型 (recharge/commission/refund) |
| amount | integer | 金额 |
| description | varchar(255) | 描述 |
| reference_type | varchar(100) | 关联类型 |
| reference_id | bigint | 关联ID |
| created_at | timestamp | 创建时间 |

**索引**：
- PRIMARY: financial_record_id
- INDEX: tenant_id
- INDEX: type
- INDEX: created_at

---

### system_settings - 系统配置表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint | 主键 |
| group | varchar(50) | 配置组 |
| key | varchar(100) | 配置键 |
| value | text | 配置值 |
| is_encrypted | boolean | 是否加密 |
| description | varchar(255) | 描述 |
| created_at | timestamp | 创建时间 |
| updated_at | timestamp | 更新时间 |

**索引**：
- PRIMARY: id
- UNIQUE: group + key
- INDEX: group

---

### audit_logs - 审计日志表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint | 主键 |
| tenant_id | bigint | 租户ID |
| user_id | bigint | 用户ID |
| action | varchar(50) | 操作 |
| resource_type | varchar(100) | 资源类型 |
| resource_id | bigint | 资源ID |
| old_values | json | 旧值 |
| new_values | json | 新值 |
| ip_address | varchar(45) | IP地址 |
| user_agent | varchar(255) | User Agent |
| created_at | timestamp | 创建时间 |

**索引**：
- PRIMARY: id
- INDEX: tenant_id
- INDEX: user_id
- INDEX: action
- INDEX: created_at

---

### oauth_accounts - OAuth账号表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint | 主键 |
| user_id | bigint | 用户ID |
| provider | varchar(50) | 提供商 (wechat/dingtalk/feishu/alipay) |
| provider_id | varchar(255) | 提供商用户ID |
| access_token | text | 访问Token（加密存储） |
| refresh_token | text | 刷新Token（加密存储） |
| expires_at | timestamp | 过期时间 |
| created_at | timestamp | 创建时间 |
| updated_at | timestamp | 更新时间 |

**索引**：
- PRIMARY: id
- UNIQUE: provider + provider_id
- INDEX: user_id

---

## RBAC 权限域

### permissions - 权限表

| 字段 | 类型 | 说明 |
|------|------|------|
| permission_id | bigint | 主键，16位随机ID |
| name | varchar(100) | 权限名称（如 tenant.view） |
| display_name | varchar(200) | 显示名称 |
| module | varchar(50) | 所属模块 |
| created_at | timestamp | |
| updated_at | timestamp | |

### roles - 角色表

| 字段 | 类型 | 说明 |
|------|------|------|
| role_id | bigint | 主键，16位随机ID |
| tenant_id | bigint | 租户ID |
| name | varchar(100) | 角色名称 |
| display_name | varchar(200) | 显示名称 |
| is_system | boolean | 是否系统内置角色 |
| created_at | timestamp | |
| updated_at | timestamp | |

### role_permissions - 角色权限关系表

| 字段 | 类型 | 说明 |
|------|------|------|
| role_id | bigint | 角色ID |
| permission_id | bigint | 权限ID |
| created_at | timestamp | |

**索引**：PRIMARY: role_id + permission_id

---

## 订阅域

### subscription_plans - 订阅计划表

| 字段 | 类型 | 说明 |
|------|------|------|
| subscription_plan_id | bigint | 主键 |
| name | varchar(50) | 计划名称 (free/basic/pro/enterprise) |
| display_name | varchar(200) | 显示名称 |
| price_monthly | integer | 月付价格（分） |
| price_yearly | integer | 年付价格（分） |
| max_members | integer | 最大成员数 |
| max_storage | bigint | 最大存储（字节） |
| features | json | 功能列表 |
| is_active | boolean | 是否启用 |
| sort_order | integer | 排序 |
| created_at | timestamp | |
| updated_at | timestamp | |

### subscription_histories - 订阅历史表

| 字段 | 类型 | 说明 |
|------|------|------|
| subscription_history_id | bigint | 主键 |
| tenant_id | bigint | 租户ID |
| subscription_plan_id | bigint | 计划ID |
| action | varchar(20) | 动作 (subscribe/upgrade/downgrade/cancel) |
| started_at | timestamp | 开始时间 |
| ended_at | timestamp | 结束时间 |
| amount | integer | 金额（分） |
| created_at | timestamp | |

---

## 支付域

### payment_orders - 支付订单表

| 字段 | 类型 | 说明 |
|------|------|------|
| payment_order_id | bigint | 主键 |
| tenant_id | bigint | 租户ID |
| user_id | bigint | 用户ID |
| order_no | varchar(64) | 订单号（唯一） |
| gateway | varchar(20) | 支付网关 (wechat/alipay/paypal/stripe/unionpay) |
| amount | integer | 金额（分） |
| currency | varchar(3) | 币种 |
| status | varchar(20) | 状态 (pending/paid/refunded/failed) |
| paid_at | timestamp | 支付时间 |
| expired_at | timestamp | 过期时间 |
| metadata | json | 附加数据 |
| created_at | timestamp | |
| updated_at | timestamp | |

### user_payment_passwords - 用户支付密码表

| 字段 | 类型 | 说明 |
|------|------|------|
| user_payment_password_id | bigint | 主键 |
| user_id | bigint | 用户ID |
| password_hash | varchar(255) | 密码哈希 |
| created_at | timestamp | |
| updated_at | timestamp | |

### payment_logs - 支付日志表

| 字段 | 类型 | 说明 |
|------|------|------|
| payment_log_id | bigint | 主键 |
| tenant_id | bigint | 租户ID |
| user_id | bigint | 用户ID |
| payment_order_id | bigint | 订单ID |
| action | varchar(20) | 动作 (create/pay/refund/verify) |
| result | varchar(20) | 结果 (success/fail) |
| ip_address | varchar(45) | IP 地址 |
| metadata | json | 附加数据 |
| created_at | timestamp | |

---

## 文件域

### file_uploads - 文件上传表

| 字段 | 类型 | 说明 |
|------|------|------|
| file_upload_id | bigint | 主键 |
| tenant_id | bigint | 租户ID |
| user_id | bigint | 用户ID |
| disk | varchar(20) | 磁盘 (local/s3/oss) |
| path | varchar(500) | 存储路径 |
| filename | varchar(255) | 原始文件名 |
| mime_type | varchar(100) | MIME 类型 |
| size | bigint | 文件大小（字节） |
| share_token | varchar(64) | 分享令牌 |
| share_expires_at | timestamp | 分享过期时间 |
| created_at | timestamp | |
| updated_at | timestamp | |

---

## 通知域

### notifications - 通知表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | uuid | 主键 (UUID) |
| type | varchar(255) | 通知类型 |
| notifiable_type | varchar(255) | 多态类型 |
| notifiable_id | bigint | 多态 ID |
| data | json | 通知数据 |
| read_at | timestamp | 已读时间 |
| created_at | timestamp | |
| updated_at | timestamp | |

### notification_preferences - 通知偏好表

| 字段 | 类型 | 说明 |
|------|------|------|
| notification_preference_id | bigint | 主键 |
| tenant_id | bigint | 租户ID |
| user_id | bigint | 用户ID |
| channel | varchar(20) | 通知渠道 (mail/database) |
| type | varchar(50) | 通知类型 |
| is_enabled | boolean | 是否启用 |
| created_at | timestamp | |
| updated_at | timestamp | |

---

## 审计与日志域

### structured_logs - 结构化日志表

| 字段 | 类型 | 说明 |
|------|------|------|
| structured_log_id | bigint | 主键 |
| tenant_id | bigint | 租户ID |
| user_id | bigint | 用户ID |
| level | varchar(20) | 日志级别 (info/warning/error) |
| channel | varchar(50) | 日志渠道 |
| message | text | 日志消息 |
| context | json | 上下文数据 |
| created_at | timestamp | |

---

## 系统域

### user_api_tokens - 用户 API Token 表

| 字段 | 类型 | 说明 |
|------|------|------|
| user_api_token_id | bigint | 主键 |
| tenant_id | bigint | 租户ID |
| user_id | bigint | 用户ID |
| name | varchar(100) | Token 名称 |
| token | text | API Key（加密存储） |
| abilities | json | 权限能力列表 |
| last_used_at | timestamp | 最后使用时间 |
| expires_at | timestamp | 过期时间 |
| created_at | timestamp | |
| updated_at | timestamp | |

### user_preferences - 用户偏好表

| 字段 | 类型 | 说明 |
|------|------|------|
| user_preference_id | bigint | 主键 |
| user_id | bigint | 用户ID |
| key | varchar(100) | 偏好键 |
| value | text | 偏好值 |
| created_at | timestamp | |
| updated_at | timestamp | |

**索引**：UNIQUE: user_id + key

### api_versions - API 版本表

| 字段 | 类型 | 说明 |
|------|------|------|
| api_version_id | bigint | 主键 |
| version | varchar(20) | 版本号 (v1/v2) |
| is_active | boolean | 是否启用 |
| released_at | timestamp | 发布时间 |
| deprecated_at | timestamp | 废弃时间 |
| sunset_at | timestamp | 下线时间 |
| created_at | timestamp | |

### plugins - 插件表

| 字段 | 类型 | 说明 |
|------|------|------|
| plugin_id | bigint | 主键 |
| tenant_id | bigint | 租户ID |
| name | varchar(100) | 插件名称 |
| version | varchar(20) | 插件版本 |
| status | varchar(20) | 状态 (installed/enabled/disabled) |
| config | json | 插件配置 |
| created_at | timestamp | |
| updated_at | timestamp | |

### plugin_dependencies - 插件依赖表

| 字段 | 类型 | 说明 |
|------|------|------|
| plugin_id | bigint | 插件ID |
| dependency_name | varchar(100) | 依赖插件名 |
| dependency_version | varchar(20) | 依赖版本 |

### rate_limit_rules - 速率限制规则表

| 字段 | 类型 | 说明 |
|------|------|------|
| rate_limit_rule_id | bigint | 主键 |
| tenant_id | bigint | 租户ID |
| route_pattern | varchar(255) | 路由模式 |
| max_attempts | integer | 最大尝试次数 |
| decay_minutes | integer | 时间窗口（分钟） |
| is_active | boolean | 是否启用 |
| created_at | timestamp | |
| updated_at | timestamp | |

### export_tasks - 导出任务表

| 字段 | 类型 | 说明 |
|------|------|------|
| export_task_id | bigint | 主键 |
| tenant_id | bigint | 租户ID |
| user_id | bigint | 用户ID |
| type | varchar(50) | 导出类型 (excel/pdf) |
| status | varchar(20) | 状态 (pending/processing/completed/failed) |
| file_path | varchar(500) | 文件路径 |
| total_rows | integer | 总行数 |
| processed_rows | integer | 已处理行数 |
| created_at | timestamp | |
| updated_at | timestamp | |

### alert_rules - 告警规则表

| 字段 | 类型 | 说明 |
|------|------|------|
| alert_rule_id | bigint | 主键 |
| tenant_id | bigint | 租户ID |
| name | varchar(100) | 规则名称 |
| metric | varchar(50) | 监控指标 |
| threshold | decimal | 阈值 |
| operator | varchar(10) | 比较运算符 |
| is_active | boolean | 是否启用 |
| created_at | timestamp | |
| updated_at | timestamp | |

### alerts - 告警记录表

| 字段 | 类型 | 说明 |
|------|------|------|
| alert_id | bigint | 主键 |
| alert_rule_id | bigint | 规则ID |
| tenant_id | bigint | 租户ID |
| value | decimal | 触发值 |
| message | text | 告警消息 |
| resolved_at | timestamp | 解决时间 |
| created_at | timestamp | |

---

## 框架基础表

### personal_access_tokens - Sanctum Token 表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint | 主键 (auto-increment) |
| tokenable_type | varchar(255) | 多态类型 |
| tokenable_id | bigint | 多态 ID |
| name | varchar(255) | Token 名称 |
| token | varchar(64) | Token 哈希 (sha256) |
| abilities | text | 权限能力 (JSON) |
| last_used_at | timestamp | 最后使用时间 |
| expires_at | timestamp | 过期时间 |
| created_at | timestamp | |
| updated_at | timestamp | |

### cache - 缓存表

| 字段 | 类型 | 说明 |
|------|------|------|
| key | varchar(255) | 主键 |
| value | text | 缓存值 |
| expiration | integer | 过期时间戳 |

### sessions - 会话表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | varchar(255) | 主键 (Session ID) |
| user_id | bigint | 用户ID |
| ip_address | varchar(45) | IP 地址 |
| user_agent | text | User Agent |
| payload | text | 会话数据 |
| last_activity | integer | 最后活动时间戳 |

---

## ER 图

```
┌─────────────┐       ┌─────────────┐       ┌─────────────┐
│   tenants   │       │ tenant_users│       │    users    │
├─────────────┤       ├─────────────┤       ├─────────────┤
│ tenant_id   │──┐    │ tenant_id   │    ┌──│ user_id     │
│ name        │  │    │ user_id     │────┘  │ name        │
│ slug        │  │    │ role        │       │ email       │
│ custom_domain│  │    │ is_active   │       │ role        │
│ status      │  │    └─────────────┘       │ password    │
│ ...         │  │                          │ ...         │
└─────────────┘  │                          └─────────────┘
                 │
                 │    ┌─────────────┐
                 │    │tenant_settings│
                 │    ├─────────────┤
                 ├───▶│ tenant_id   │
                 │    │ group       │
                 │    │ key         │
                 │    │ value       │
                 │    └─────────────┘
                 │
                 │    ┌─────────────┐
                 │    │credit_accounts│
                 │    ├─────────────┤
                 ├───▶│ tenant_id   │
                 │    │ user_id     │
                 │    │ balance     │
                 │    └─────────────┘
                 │
                 │    ┌─────────────┐
                 │    │credit_transactions│
                 │    ├─────────────┤
                 └───▶│ tenant_id   │
                      │ credit_account_id│
                      │ type        │
                      │ amount      │
                      └─────────────┘
```

---

## 数据隔离

所有业务表都包含 `tenant_id` 字段，通过 `TenantScope` 全局作用域实现数据隔离：

```php
// 自动添加租户过滤
Order::all();
// SQL: SELECT * FROM orders WHERE tenant_id = 123456

// 跨租户查询（Super Admin）
Order::withoutGlobalScope(TenantScope::class)->get();
// SQL: SELECT * FROM orders
```

---

**文档版本**: v1.0.0  
**最后更新**: 2026-06-28
