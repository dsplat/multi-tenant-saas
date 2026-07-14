# 功能完整性审查计划

> **目标**: 审查平台后台、租户后台、SPA API 的功能完备性和接口规范一致性

---

## 一、审查范围

### 1.1 平台后台（Admin）

| 模块 | Controller | 路由文件 | 功能 |
|------|-----------|---------|------|
| Auth | RbacController | admin.php | 角色权限管理 |
| Auth | AuthController | admin.php | SSO 配置 |
| User | TenantController | admin.php | 租户 CRUD |
| User | TenantMemberController | admin.php | 租户成员管理 |
| Billing | SubscriptionController | admin.php | 订阅计划管理 |
| Billing | TenantCreditController | admin.php | 积分概览 |
| Payment | TenantPaymentController | admin.php | 支付配置 |
| Storage | FileController | admin.php | 文件管理 |
| Domain | TenantDomainController | admin.php | 域名管理 |
| SSL | TenantSslController | admin.php | SSL 管理 |
| ApiToken | TenantTokenController | admin.php | API Token 管理 |
| Logging | TenantAuditController | admin.php | 审计日志 |
| Platform | AdminSettingsController | admin.php | 平台设置 |
| Infrastructure | ModuleController | admin.php | 模块管理 |
| Operator | OperatorController | admin.php | 运营人员管理 |
| Monitoring | *(closure)* | admin.php | 监控告警 |
| Plugin | *(closure)* | admin.php | 插件管理 |
| Workflow | *(closure)* | admin.php | 工作流管理 |

### 1.2 租户后台（Console）

| 模块 | Controller | 路由文件 | 功能 |
|------|-----------|---------|------|
| Auth | MfaController | tenant.php | MFA 管理 |
| Auth | AuthController | tenant.php | OAuth 配置 |
| User | TenantController | tenant.php | 租户资料 |
| User | TenantSettingController | tenant.php | 租户设置 |
| User | TenantMemberController | tenant.php | 成员管理 |
| Billing | SubscriptionController | tenant.php | 订阅管理 |
| Billing | TenantCreditController | tenant.php | 积分管理 |
| Billing | TenantQuotaController | tenant.php | 配额管理 |
| Payment | TenantPaymentController | tenant.php | 支付管理 |
| Storage | FileController | tenant.php | 文件管理 |
| Domain | TenantDomainController | tenant.php | 域名管理 |
| SSL | TenantSslController | tenant.php | SSL 管理 |
| ApiToken | TenantTokenController | tenant.php | API Token |
| Notification | NotificationController | tenant.php | 通知管理 |
| Operator | OperatorController | tenant.php | 运营人员 |
| Monitoring | *(closure)* | tenant.php | 监控 |
| Workflow | *(closure)* | tenant.php | 工作流 |
| Conversation | *(closure)* | tenant.php | 会话 |
| Event | *(closure)* | tenant.php | 事件 |
| DeveloperPortal | *(closure)* | tenant.php | 开发者门户 |

### 1.3 SPA API

| 模块 | Controller | 路由文件 | 功能 |
|------|-----------|---------|------|
| Auth | AuthController | api.php | 登录/注册/MFA |
| Auth | MfaController | api.php | MFA 管理 |
| User | TenantController | api.php | 租户 CRUD |
| User | TenantMemberController | api.php | 成员管理 |
| Billing | SubscriptionController | api.php | 订阅管理 |
| Billing | TenantCreditController | api.php | 积分管理 |
| Payment | TenantPaymentController | api.php | 支付管理 |
| Storage | FileController | api.php | 文件管理 |
| Notification | NotificationController | api.php | 通知管理 |
| AI | AgentController | api.php | Agent 管理 |
| AI | AgentChatController | api.php | Agent 对话 |
| Voting | VotingController | api.php | 投票系统 |
| Lottery | LotteryController | api.php | 抽奖系统 |
| Form | FormController | api.php | 表单系统 |
| Coupon | CouponController | api.php | 优惠券系统 |
| SMS | SmsController | api.php | 短信系统 |

---

## 二、审查维度

### 2.1 功能完备性

- [ ] 每个模块的 CRUD 操作是否完整（Create/Read/Update/Delete）
- [ ] 列表接口是否有分页、筛选、排序
- [ ] 详情接口是否返回完整数据
- [ ] 创建/更新接口是否有完整的验证
- [ ] 删除接口是否有软删除支持
- [ ] 批量操作是否支持
- [ ] 导出功能是否实现

### 2.2 权限控制

- [ ] 平台后台路由是否都有权限中间件
- [ ] 租户后台路由是否都有权限中间件
- [ ] SPA API 路由是否都有权限中间件
- [ ] 公开路由是否正确配置
- [ ] 跨租户访问是否被阻止
- [ ] 角色权限是否正确映射

### 2.3 接口规范

- [ ] 响应格式是否一致（success, data, message）
- [ ] 错误响应格式是否一致
- [ ] 分页格式是否一致
- [ ] 验证错误格式是否一致
- [ ] HTTP 状态码是否正确使用
- [ ] Content-Type 是否正确设置

### 2.4 输入输出

- [ ] 请求参数验证是否完整
- [ ] 响应数据是否包含必要字段
- [ ] 敏感数据是否正确隐藏
- [ ] 日期格式是否一致
- [ ] ID 格式是否一致（bigint）

---

## 三、审查计划

### 阶段 1: 平台后台审查（Task 1-3）

**Task 1: Auth + Operator 模块**
- RbacController: 角色 CRUD、权限分配
- AuthController: SSO 配置
- OperatorController: 运营人员管理

**Task 2: User + Billing + Payment 模块**
- TenantController: 租户 CRUD、暂停/激活
- TenantMemberController: 成员管理
- SubscriptionController: 订阅计划
- TenantCreditController: 积分管理
- TenantPaymentController: 支付配置

**Task 3: Infrastructure + Domain + SSL + Storage + Logging 模块**
- ModuleController: 模块启停
- TenantDomainController: 域名管理
- TenantSslController: SSL 管理
- FileController: 文件管理
- TenantAuditController: 审计日志

### 阶段 2: 租户后台审查（Task 4-6）

**Task 4: Auth + User 模块**
- MfaController: MFA 管理
- AuthController: OAuth 配置
- TenantController: 租户资料
- TenantSettingController: 租户设置
- TenantMemberController: 成员管理

**Task 5: Billing + Payment + Storage 模块**
- SubscriptionController: 订阅管理
- TenantCreditController: 积分管理
- TenantQuotaController: 配额管理
- TenantPaymentController: 支付管理
- FileController: 文件管理

**Task 6: Notification + Operator + Monitoring + Workflow 模块**
- NotificationController: 通知管理
- OperatorController: 运营人员管理
- Monitoring routes: 监控
- Workflow routes: 工作流

### 阶段 3: SPA API 审查（Task 7-9）

**Task 7: Auth + User + Billing API**
- 登录/注册/MFA
- 租户 CRUD
- 成员管理
- 订阅管理
- 积分管理

**Task 8: Payment + Storage + Notification API**
- 支付管理
- 文件管理
- 通知管理

**Task 9: AI + Voting + Lottery + Form + Coupon + SMS API**
- Agent 管理和对话
- 投票系统
- 抽奖系统
- 表单系统
- 优惠券系统
- 短信系统

### 阶段 4: 集成测试（Task 10）

**Task 10: 端到端测试**
- 平台管理员登录 → 创建租户 → 配置
- 租户管理员登录 → 管理成员 → 配置
- 普通用户登录 → 使用功能
- 权限验证 → 跨租户访问阻止

---

## 四、预期输出

1. **功能缺失清单**: 列出缺失或不完整的功能
2. **权限问题清单**: 列出权限控制问题
3. **接口规范问题**: 列出格式不一致的接口
4. **修复建议**: 每个问题的修复方案
5. **测试用例**: 关键功能的测试用例

---

## 五、执行策略

- 使用 subagent 并行审查不同模块
- 每个 Task 完成后进行 review
- 发现问题立即记录，不阻塞后续审查
- 最后汇总所有问题，制定修复计划
