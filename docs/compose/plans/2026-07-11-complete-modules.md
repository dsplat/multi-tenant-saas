# 模块完善计划 — 补全 14 个薄包装模块

## 目标

将 14 个薄包装模块（只有 ServiceProvider）补全为完整业务模块，包含 Controllers、Routes、Resources、Requests、Console Commands。

## 模块清单

| 优先级 | 模块 | 当前状态 | 需要补全 |
|---|---|---|---|
| P0 | **Billing** | 有 Controllers + Commands + Routes | Resources、Requests |
| P0 | **User** | 有 Controllers + Resources + Routes | Requests |
| P0 | **Auth** | 有 Controllers + Routes | Resources、Requests |
| P1 | **Infrastructure** | 有 Controller + Routes | Resources |
| P1 | **Logging** | 有 Controller + Routes | Resources |
| P1 | **Platform** | 有 Controller + Routes | Resources |
| P2 | **Domain** | 有 Controller + Routes | Resources、Commands |
| P2 | **SSL** | 有 Controller + Routes | Resources |
| P2 | **Payment** | 有 Controller + Routes | Resources、Commands |
| P2 | **ApiToken** | 有 Controller + Routes | Resources |
| P3 | **Notification** | 有 Controller + Routes | Resources |
| P3 | **Storage** | 有 Controller + Routes | Resources |
| P3 | **Event** | 只有 ServiceProvider | 全部 |
| P3 | **Plugin** | 只有 ServiceProvider | 全部 |
| P3 | **Monitoring** | 只有 ServiceProvider | 全部 |
| P3 | **DeveloperPortal** | 只有 ServiceProvider | 全部 |
| P3 | **Conversation** | 有 Resources | Controllers + Routes |
| P3 | **Workflow** | 只有 ServiceProvider | 全部 |

## 执行策略

按批次执行，每批次 3-4 个模块，每个模块：
1. 创建 `Http/Controllers/` + Controller 文件
2. 创建 `routes/api.php` + `routes/public.php`
3. 创建 `Http/Resources/` + Resource 文件
4. 创建 `Http/Requests/` + Request 文件（如需要）
5. 更新 ServiceProvider 注册路由
6. 运行测试验证

## 批次安排

### Batch 1 (P0) — Billing + User + Auth 补全
- Billing: 添加 Resources (SubscriptionResource, CreditResource) + Requests
- User: 添加 Requests (StoreTenantRequest, UpdateTenantRequest, StoreMemberRequest)
- Auth: 添加 Resources (UserResource) + Requests (LoginRequest, RegisterRequest)

### Batch 2 (P1) — Infrastructure + Logging + Platform
- Infrastructure: 添加 Resources (ModuleResource)
- Logging: 添加 Resources (AuditLogResource)
- Platform: 添加 Resources (SettingsResource)

### Batch 3 (P2) — Domain + SSL + Payment + ApiToken
- Domain: 添加 Resources + Commands (domain:verify)
- SSL: 添加 Resources + Commands (ssl:renew)
- Payment: 添加 Resources + Commands (payment:notify)
- ApiToken: 添加 Resources

### Batch 4 (P3) — 剩余模块
- Event: 创建完整模块 (Controller + Routes + Resources)
- Plugin: 创建完整模块
- Monitoring: 创建完整模块
- DeveloperPortal: 创建完整模块
- Conversation: 添加 Controllers + Routes
- Workflow: 创建完整模块
- Notification: 补全 Resources
- Storage: 补全 Resources

## 验证

每个批次完成后：
1. `composer test` — 全部通过
2. `php artisan module:list` — 模块状态正确
3. Pint 检查通过
