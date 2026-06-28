# 设计决策文档

> 记录框架关键设计决策的"为什么"

---

## 1. 为什么选择全局 ID（16 位随机数字）

**决策**：所有表主键使用 16 位随机数字 ID（范围 `1000000000000000` ~ `9007199254740991`），而非自增 ID。

**理由**：
1. **JavaScript 安全**：上限为 `Number.MAX_SAFE_INTEGER`，前端可直接使用无需字符串转换
2. **全局唯一**：所有表共用 ID 空间，跨表关联无冲突（如 `user_id` 与 `order_id` 不会重复）
3. **不可推测**：完全随机，无法通过遍历 ID 推测业务量或用户数
4. **碰撞概率极低**：8 万亿可用 ID，单表碰撞概率 < 10⁻¹²
5. **分布式友好**：无需中心化 ID 分配器，任意节点可独立生成

**代价**：
- 索引体积比自增 int 大（可接受）
- 无法按 ID 排序推断创建顺序（用 `created_at` 代替）

**实现**：`HasGlobalId` Trait + `IdGenerator` 服务，可替换。

---

## 2. 为什么选择租户隔离策略（共享数据库 + tenant_id 字段）

**决策**：所有租户数据存储在同一数据库，通过 `tenant_id` 字段隔离，而非每租户独立数据库。

**理由**：
1. **运维成本低**：无需为每个租户维护独立数据库，备份/迁移/监控集中管理
2. **资源利用率高**：小租户共享资源，避免为 5 用户的租户分配独立数据库
3. **跨租户分析**：admin 域名可执行聚合查询（如统计所有租户使用量）
4. **扩展灵活**：若未来某租户数据量超大，可单独迁移到独立库（渐进式拆分）

**代价**：
- 需要严格的 `tenant_id` 过滤（通过 `TenantScope` 全局作用域自动应用）
- 单表数据量大时需分表/分区

**安全保证**：
- `BelongsToTenant` Trait 自动添加全局作用域
- `withoutTenantScope()` / `withTenant()` / `forAllTenants()` 仅 admin 域名可用
- `TenantContext::getDomainType()` 验证调用上下文

---

## 3. 为什么选择多域名方案

**决策**：通过不同域名区分访问入口：`admin.*`（平台管理）、`console.*`（租户后台）、`api.*`（API）、`app.*`（终端用户）。

**理由**：
1. **安全隔离**：admin 域名可部署 IP 白名单/VPN 限制，与终端用户入口物理隔离
2. **SSL 独立**：每个域名可使用独立证书，租户自定义域名也可独立配置 SSL
3. **缓存隔离**：CDN/Nginx 可按域名配置不同缓存策略
4. **语义清晰**：URL 即可判断访问场景，便于日志分析与监控

**代价**：
- 需要域名解析与证书管理（通过 `DomainService` + `TenantSslService` 自动化）
- 多域名 Nginx 配置较复杂（通过 `GenerateNginxDomainMap` 命令自动生成）

---

## 4. 为什么选择模块化设计

**决策**：核心功能以模块形式实现（`src/Modules/`），支持按需加载。

**理由**：
1. **按需启用**：`config('apitoken.enabled')` 控制是否加载 ApiToken 模块，未启用时不注册服务
2. **解耦**：模块自带 Config/Models/Services/Controllers，内部高内聚
3. **可替换**：派生项目可实现自己的模块替换默认实现
4. **可扩展**：新增功能只需新建模块，不修改核心代码

**当前模块**：
- `ApiToken`：API Token 管理（对接 New API）
- `Domain`：域名管理与 Nginx 配置生成
- `Payment`：第三方支付网关适配
- `SSL`：SSL 证书管理

**新增服务模块**（TASK-001）：
- 10 个核心服务以 `src/Services/*.php` 形式提供，通过 DI 注入使用
- 每个服务自带完整的 PHPDoc、类型声明、异常处理
- 在 `TenancyServiceProvider::registerCoreServices()` 中以 singleton 注册

---

## 5. 为什么选择配置驱动而非硬编码

**决策**：所有可配置项均通过 `config/` 目录或 `tenant_settings` / `system_settings` 表管理。

**理由**：
1. **多租户差异**：不同租户的支付配置、OAuth 配置、邮件配置各不相同
2. **环境隔离**：开发/测试/生产环境通过 `.env` 切换，无需改代码
3. **运行时调整**：管理员可在后台修改配置而无需重新部署
4. **加密存储**：敏感配置（密钥、密码）通过 `is_encrypted` 标记自动加密

**实现**：
- 系统级配置：`config/*.php` + `SystemSetting` 模型
- 租户级配置：`tenant_settings` 表 + `TenantSetting` 模型
- 用户级配置：`user_preferences` 表（TASK-001 新增）

---

## 6. 为什么选择 Octane 友好设计

**决策**：所有上下文状态通过 `Request` attributes 传递，不使用静态属性或 `config()` 写入。

**理由**：
1. **Octane/Swoole** 下工作进程复用，静态属性会跨请求污染
2. `config()` 写入在 Octane 下持久化，导致下一个请求读到上一个请求的配置
3. `Request` 对象每次请求都是新实例，天然隔离

**实现**：
- `TenantContext` 使用 `request()->attributes` 存储租户信息
- `SocialiteService` 使用 `app()` 容器保存原始配置（请求级隔离）
- `CacheService` 使用 `Cache::remember()` 而非静态变量

---

## 7. 为什么选择 yansongda/pay 作为支付 SDK

**决策**：微信/支付宝支付使用 `yansongda/pay` v3，而非自行实现。

**理由**：
1. **官方维护**：持续更新，跟随微信/支付宝 API 变化
2. **多驱动统一**：微信和支付宝通过同一接口调用
3. **验签内置**：回调验签由 SDK 处理
4. **Laravel 集成**：提供 Facade 与配置文件

**扩展**：
- PayPal / Stripe / 银联支付通过独立 Service 类实现
- 复用 `PayService` 的租户级配置管理
- 各 Service 提供统一的 `createOrder` / `refund` / `handleWebhook` 接口

---

## 8. 为什么选择 RBAC 而非硬编码角色检查

**决策**：采用 RBAC（Role-Based Access Control）三表模型（permissions / roles / role_permissions），而非在中间件中硬编码角色检查。

**理由**：
1. **灵活性**：租户可创建自定义角色并分配权限，无需修改代码
2. **细粒度**：40+ 权限节点（如 `tenant.view`、`member.create`），比角色检查更精确
3. **可维护**：新增功能只需添加权限节点和 seed，无需修改中间件逻辑
4. **可扩展**：未来可支持权限继承、条件权限等高级特性

**实现**：
- `CheckRbacPermission` 中间件通过路由别名匹配权限节点
- `RbacService` 提供角色/权限 CRUD + 权限检查
- 默认 seed 包含 `super_admin` / `platform_user` / `tenant_admin` / `end_user` 四个角色

---

## 9. 为什么引入领域事件系统

**决策**：关键业务操作触发领域事件，由 `LogEventListener` 异步记录审计日志。

**理由**：
1. **解耦**：业务逻辑与审计/通知逻辑分离
2. **可扩展**：新增监听器无需修改业务代码
3. **事务安全**：`$afterCommit = true` 确保事务回滚时不记录幽灵状态
4. **可追溯**：所有关键操作自动留痕

**实现**：
- 5 个事件类：`UserRegistered` / `TenantCreated` / `TenantSuspended` / `CreditLow` / `SubscriptionExpiring`
- `LogEventListener` 自动监听所有事件并记录到 `audit_logs` 表
- 通知类通过 Laravel Notification 系统异步发送

---

## 10. 为什么进行全面 i18n 改造

**决策**：所有用户可见的文本（响应消息、邮件正文、通知内容）均使用 `trans()` 国际化。

**理由**：
1. **双语支持**：框架同时支持 `zh_CN` 和 `en`，满足国际化需求
2. **一致性**：统一使用语言文件，避免硬编码中文散落各处
3. **可维护**：修改文案只需编辑语言文件，无需改代码
4. **可扩展**：未来可轻松添加更多语言

**实现**：
- 13 个语言文件 × 2 种语言（zh_CN + en）
- `SetLocale` 中间件自动设置请求语言
- `EmailVerificationMail` / `PasswordResetMail` 邮件主题/正文均使用 `trans()`
- 控制器响应消息统一使用 `trans()`

---

## 11. 为什么使用 Sanctum Token abilities

**决策**：API Token 使用 Sanctum 的 abilities 机制，实现 14 种细粒度 API 权限。

**理由**：
1. **Laravel 原生**：Sanctum 是 Laravel 官方推荐的 API 认证方案
2. **细粒度**：Token 可限定只能执行特定操作（如只读 vs 读写）
3. **安全**：Token 可撤销，不会永久有效
4. **简单**：无需额外库，`tokenCan()` 方法即可检查权限

**实现**：
- 14 种 abilities：`read` / `write` / `manage-members` / `manage-billing` 等
- `UserApiToken` 模型管理用户级 Token
- 创建 Token 时指定 abilities

---

## 12. 为什么引入 API 版本管理

**决策**：通过 `ApiVersionService` + `api_versions` 表实现 API 多版本共存与废弃通知。

**理由**：
1. **向后兼容**：新版本上线时旧版本仍可用
2. **渐进迁移**：给开发者时间适配新版本
3. **废弃通知**：通过响应头通知客户端版本即将废弃
4. **可追溯**：记录每个版本的发布日期和废弃日期

**实现**：
- `ApiVersionService` 管理版本生命周期
- 路由按版本前缀分组（`/api/v1`、`/api/v2`）
- 废弃版本返回 `Deprecation` 响应头

---

## 13. 为什么引入插件系统

**决策**：通过 `PluginService` + `plugins` / `plugin_dependencies` 表实现租户级插件管理。

**理由**：
1. **可扩展**：第三方可在不修改框架代码的情况下添加功能
2. **租户隔离**：每个租户可独立安装/卸载插件
3. **依赖管理**：插件可声明依赖其他插件
4. **生命周期**：支持安装/启用/禁用/卸载完整流程

**实现**：
- `PluginService` 管理插件生命周期
- `plugins` 表记录租户级插件状态
- `plugin_dependencies` 表记录插件间依赖关系
- 插件通过 ServiceProvider 注册路由和服务
