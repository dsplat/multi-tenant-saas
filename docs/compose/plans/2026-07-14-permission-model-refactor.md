# 权限模型重构 — 最终设计文档

> **方案**: Users（租户用户）+ Operators（运营人员）+ OperatorTenants（映射）三层分离
> **核心原则**: 租户完全隔离，邀请制添加 operator，框架预设通用角色

---

## 一、角色体系

### 1.1 平台级角色（operators.scope = 'platform'）

| 角色标识 | 名称 | 说明 |
|---------|------|------|
| `super_admin` | 超级管理员 | 全平台所有权限，系统初始化时创建 |
| `platform_admin` | 平台运营 | 管理租户、查看平台数据 |
| `platform_support` | 平台客服 | 只读查看租户信息，处理跨租户问题 |

### 1.2 租户级角色（operator_tenants.role）

| 角色标识 | 名称 | 说明 |
|---------|------|------|
| `tenant_admin` | 租户管理员 | 本租户全权限 |
| `member` | 成员 | 基本操作权限 |
| `viewer` | 只读 | 查看权限 |
| `order_manager` | 订单员 | 订单查看、创建、处理 |
| `sales` | 销售员 | 客户管理、订单创建、报价 |
| `marketing` | 市场推广员 | 活动管理、优惠券、推广 |
| `support_agent` | 售后客服 | 工单处理、客户沟通、退款申请 |
| `finance` | 财务 | 订单查看、支付管理、发票、报表 |
| `analyst` | 数据分析员 | 报表查看、数据导出、统计 |

### 1.3 普通用户（users，不是 operator）

| 角色标识 | 名称 | 说明 |
|---------|------|------|
| `end_user` | 终端用户 | 使用业务功能，无管理权限 |

### 1.4 角色层级

```
平台级（operators.scope = 'platform'）
super_admin
  ├── platform_admin
  └── platform_support

租户级（operator_tenants.role）
tenant_admin
  ├── member
  ├── viewer
  ├── order_manager
  ├── sales
  ├── marketing
  ├── support_agent
  ├── finance
  └── analyst
```

### 1.5 权限节点（42 个）

```
tenant:       create, update, delete, suspend, activate, view
member:       create, update, delete, view
credit:       view, recharge, adjust
setting:      view, update
payment:      view, create, refund
domain:       manage
ssl:          manage
audit:        view
rbac:         manage
file:         upload, delete
subscription: manage
lottery:      view, create, update, delete, draw
voting:       view, create, update, delete, vote
form:         view, create, update, delete, export
coupon:       view, create, update, delete, redeem
```

---

## 二、数据库设计

### 2.1 users 表（租户用户，完全隔离）

```sql
CREATE TABLE users (
    user_id              BIGINT UNSIGNED PRIMARY KEY,
    tenant_id            BIGINT UNSIGNED NOT NULL,
    email                VARCHAR(255) NOT NULL,
    name                 VARCHAR(255) NOT NULL,
    password             VARCHAR(255),          -- 可为空（operator 的 user 记录）
    phone                VARCHAR(20),
    avatar               VARCHAR(500),
    is_active            BOOLEAN DEFAULT TRUE,
    email_verified_at    TIMESTAMP,
    last_active_at       TIMESTAMP,
    login_attempts       INT DEFAULT 0,
    locked_until         TIMESTAMP,
    password_changed_at  TIMESTAMP,
    remember_token       VARCHAR(100),
    created_at           TIMESTAMP,
    updated_at           TIMESTAMP,
    deleted_at           TIMESTAMP,

    UNIQUE KEY uk_tenant_email (tenant_id, email),
    INDEX idx_tenant (tenant_id),
    INDEX idx_email (email)
);
```

**关键变化**：
- 新增 `tenant_id`，用户属于且仅属于一个租户
- 移除 `role` 字段
- 唯一约束改为 `(tenant_id, email)`
- `password` 允许为空（operator 的 user 记录不需要密码）

### 2.2 operators 表（运营人员，独立账号体系）

```sql
CREATE TABLE operators (
    operator_id          BIGINT UNSIGNED PRIMARY KEY,
    email                VARCHAR(255) NOT NULL,
    name                 VARCHAR(255) NOT NULL,
    password             VARCHAR(255),          -- 邀请时为空，首次登录时设置
    phone                VARCHAR(20),
    avatar               VARCHAR(500),
    scope                VARCHAR(20) NOT NULL,  -- 'platform' | 'tenant'
    is_active            BOOLEAN DEFAULT FALSE, -- 邀请时未激活，设置密码后激活
    email_verified_at    TIMESTAMP,
    last_login_at        TIMESTAMP,
    login_attempts       INT DEFAULT 0,
    locked_until         TIMESTAMP,
    password_changed_at  TIMESTAMP,
    invite_token         VARCHAR(100),          -- 邀请 token
    invite_expires_at    TIMESTAMP,             -- 邀请过期时间
    created_at           TIMESTAMP,
    updated_at           TIMESTAMP,
    deleted_at           TIMESTAMP,

    UNIQUE KEY uk_email (email),
    INDEX idx_scope (scope),
    INDEX idx_active (is_active)
);
```

**关键设计**：
- `scope = 'platform'`：平台运营人员
- `scope = 'tenant'`：租户运营人员
- `is_active = FALSE`：邀请时未激活，设置密码后激活
- `invite_token`：邀请链接中的 token

### 2.3 operator_tenants 表（operator ↔ 租户映射）

```sql
CREATE TABLE operator_tenants (
    id                   BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    operator_id          BIGINT UNSIGNED NOT NULL,
    tenant_id            BIGINT UNSIGNED NOT NULL,
    user_id              BIGINT UNSIGNED NOT NULL,
    role                 VARCHAR(50) NOT NULL,
    role_id              BIGINT UNSIGNED,
    is_active            BOOLEAN DEFAULT TRUE,
    invited_at           TIMESTAMP,
    accepted_at          TIMESTAMP,
    created_at           TIMESTAMP,
    updated_at           TIMESTAMP,

    UNIQUE KEY uk_operator_tenant (operator_id, tenant_id),
    FOREIGN KEY (operator_id) REFERENCES operators(operator_id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_tenant (tenant_id),
    INDEX idx_user (user_id)
);
```

**关键设计**：
- `user_id`：operator 在该租户的 user 账号（系统自动创建）
- `role`：operator 在该租户的角色
- `invited_at`：邀请时间
- `accepted_at`：接受邀请时间（设置密码后）

### 2.4 roles 表（已有，扩展）

```sql
-- 系统预设角色
INSERT INTO roles (role_id, tenant_id, name, display_name, description, is_system) VALUES
-- 平台级
(1, NULL, 'super_admin', '超级管理员', '全平台所有权限', TRUE),
(2, NULL, 'platform_admin', '平台运营', '管理租户和平台数据', TRUE),
(3, NULL, 'platform_support', '平台客服', '只读查看租户信息', TRUE),
-- 租户级
(4, NULL, 'tenant_admin', '租户管理员', '本租户全权限', TRUE),
(5, NULL, 'member', '成员', '基本操作权限', TRUE),
(6, NULL, 'viewer', '只读', '查看权限', TRUE),
(7, NULL, 'order_manager', '订单员', '订单管理', TRUE),
(8, NULL, 'sales', '销售员', '客户和订单管理', TRUE),
(9, NULL, 'marketing', '市场推广员', '活动和推广管理', TRUE),
(10, NULL, 'support_agent', '售后客服', '工单和客户沟通', TRUE),
(11, NULL, 'finance', '财务', '支付和财务管理', TRUE),
(12, NULL, 'analyst', '数据分析员', '报表和数据分析', TRUE),
-- 普通用户
(13, NULL, 'end_user', '终端用户', '使用业务功能', TRUE);
```

### 2.5 tenant_users 表（保留，用于普通用户属性）

```sql
-- 保留表，但 role 字段废弃
-- 主要用于存储用户的租户级属性（如 credits）
-- 普通用户通过 users.tenant_id 直接归属租户
```

---

## 三、邀请流程

### 3.1 添加 Operator（邀请制）

```
租户 Admin 操作：
┌─────────────────────────────────────────────────────────────┐
│ 1. 进入「运营人员管理」页面                                    │
│ 2. 点击「邀请运营人员」                                       │
│ 3. 输入邮箱 + 选择角色                                        │
│ 4. 点击「发送邀请」                                           │
└─────────────────────────────────────────────────────────────┘

后端处理：
┌─────────────────────────────────────────────────────────────┐
│ 1. 检查 operators 表是否有该邮箱                              │
│    ├── 有：复用现有 operator（检查是否已在此租户）              │
│    └── 没有：创建新 operator（is_active=FALSE）               │
│                                                             │
│ 2. 检查 operator_tenants 是否已有此映射                       │
│    ├── 有且 active：返回错误「已在租户中」                      │
│    ├── 有但 inactive：重新激活                                │
│    └── 没有：继续                                            │
│                                                             │
│ 3. 在 users 表创建该租户的 user                               │
│    ├── tenant_id = 当前租户                                   │
│    ├── email = 邀请邮箱                                       │
│    ├── name = 邮箱前缀（或空）                                 │
│    ├── password = NULL（未设置）                               │
│    └── is_active = FALSE                                     │
│                                                             │
│ 4. 创建 operator_tenants 映射                                 │
│    ├── operator_id                                           │
│    ├── tenant_id = 当前租户                                   │
│    ├── user_id = 新创建的 user_id                             │
│    ├── role = 选择的角色                                      │
│    └── invited_at = now()                                    │
│                                                             │
│ 5. 生成 invite_token，保存到 operators 表                     │
│                                                             │
│ 6. 发送邀请邮件                                              │
└─────────────────────────────────────────────────────────────┘

邀请邮件内容：
┌─────────────────────────────────────────────────────────────┐
│ 主题：您被邀请成为 [租户名称] 的运营人员                        │
│                                                             │
│ 内容：                                                       │
│ 您好，                                                       │
│                                                             │
│ 您已被邀请成为 [租户名称] 的 [角色名称]。                       │
│ 请点击以下链接设置您的密码并激活账号：                           │
│                                                             │
│ https://console.mynet.club/invite?token=xxx                 │
│                                                             │
│ 此链接 7 天内有效。                                           │
└─────────────────────────────────────────────────────────────┘
```

### 3.2 被邀请人接受邀请

```
被邀请人操作：
┌─────────────────────────────────────────────────────────────┐
│ 1. 收到邀请邮件                                              │
│ 2. 点击链接 → 跳转到设置密码页面                               │
│ 3. 输入密码 + 确认密码                                        │
│ 4. 点击「激活账号」                                           │
└─────────────────────────────────────────────────────────────┘

后端处理：
┌─────────────────────────────────────────────────────────────┐
│ 1. 验证 invite_token 有效且未过期                             │
│                                                             │
│ 2. 更新 operators 表                                         │
│    ├── password = Hash(新密码)                                │
│    ├── is_active = TRUE                                      │
│    ├── email_verified_at = now()                             │
│    └── invite_token = NULL                                   │
│                                                             │
│ 3. 更新 users 表（对应的 user 记录）                          │
│    ├── password = Hash(新密码)（或从 operators 同步）          │
│    ├── is_active = TRUE                                      │
│    └── email_verified_at = now()                             │
│                                                             │
│ 4. 更新 operator_tenants                                     │
│    └── accepted_at = now()                                   │
│                                                             │
│ 5. 返回登录页面，提示「账号已激活，请登录」                      │
└─────────────────────────────────────────────────────────────┘
```

### 3.3 普通用户注册

```
用户操作：
┌─────────────────────────────────────────────────────────────┐
│ 1. 访问租户网站                                              │
│ 2. 点击「注册」                                              │
│ 3. 输入邮箱、密码、姓名                                       │
│ 4. 点击「注册」                                              │
└─────────────────────────────────────────────────────────────┘

后端处理：
┌─────────────────────────────────────────────────────────────┐
│ 1. 检查 (tenant_id, email) 是否已存在                        │
│    ├── 存在：返回错误「邮箱已注册」                            │
│    └── 不存在：继续                                          │
│                                                             │
│ 2. 创建 users 记录                                           │
│    ├── tenant_id = 当前租户                                   │
│    ├── email, name, password                                 │
│    └── is_active = TRUE                                      │
│                                                             │
│ 3. 发送验证邮件                                              │
│                                                             │
│ 4. 返回成功提示「请查收验证邮件」                               │
└─────────────────────────────────────────────────────────────┘
```

### 3.4 Admin 邀请普通用户

```
租户 Admin 操作：
┌─────────────────────────────────────────────────────────────┐
│ 1. 进入「用户管理」页面                                       │
│ 2. 点击「邀请用户」                                           │
│ 3. 输入邮箱 + 选择角色（可选）                                 │
│ 4. 点击「发送邀请」                                           │
└─────────────────────────────────────────────────────────────┘

后端处理：
┌─────────────────────────────────────────────────────────────┐
│ 1. 创建 users 记录（is_active=FALSE, password=NULL）          │
│ 2. 生成 invite_token                                         │
│ 3. 发送邀请邮件                                              │
│ 4. 用户点击链接 → 设置密码 → 激活                              │
└─────────────────────────────────────────────────────────────┘
```

---

## 四、登录流程

### 4.1 Operator 登录（平台后台）

```
POST /admin/auth/login (adm.mynet.club)
{
    "email": "operator@example.com",
    "password": "password123"
}

后端逻辑：
1. 查 operators 表: email = 'operator@example.com'
2. 验证密码
3. 检查 is_active = TRUE
4. 检查 login_attempts, locked_until
5. 检查 scope = 'platform'（平台后台只允许 platform scope）
6. 返回可管理的租户列表

响应：
{
    "success": true,
    "data": {
        "operator_id": 123,
        "name": "张三",
        "email": "operator@example.com",
        "scope": "platform",
        "available_tenants": [
            { "tenant_id": 1, "name": "租户A", "role": "platform_admin" },
            { "tenant_id": 2, "name": "租户B", "role": "platform_support" }
        ]
    }
}
```

### 4.2 Operator 登录（租户后台）

```
POST /console/auth/login (console.mynet.club)
{
    "email": "operator@example.com",
    "password": "password123"
}

后端逻辑：
1. 查 operators 表: email = 'operator@example.com'
2. 验证密码
3. 检查 is_active = TRUE
4. 从域名或 header 获取 tenant_id
5. 查 operator_tenants: operator_id + tenant_id
6. 检查 is_active = TRUE
7. 获取 user_id
8. 创建 Sanctum token（关联到 user_id）

响应：
{
    "success": true,
    "data": {
        "auth_token": "xxx",
        "user_id": 456,
        "tenant_id": 1,
        "role": "tenant_admin",
        "operator_id": 123
    }
}
```

### 4.3 Operator 选择租户

```
POST /admin/auth/select-tenant (adm.mynet.club)
{
    "tenant_id": 1
}

后端逻辑：
1. 验证当前 operator
2. 查 operator_tenants: operator_id + tenant_id
3. 获取 user_id
4. 创建 Sanctum token（关联到 user_id）
5. 设置 TenantContext

响应：
{
    "success": true,
    "data": {
        "auth_token": "xxx",
        "user_id": 456,
        "tenant_id": 1,
        "role": "platform_admin"
    }
}
```

### 4.4 普通用户登录

```
POST /auth/login (app.mynet.club 或 tenant.example.com)
{
    "email": "user@example.com",
    "password": "password123"
}

后端逻辑：
1. 从域名解析 tenant_id
2. 查 users 表: tenant_id = X, email = 'user@example.com'
3. 验证密码
4. 检查 is_active = TRUE
5. 创建 Sanctum token

响应：
{
    "success": true,
    "data": {
        "auth_token": "xxx",
        "user_id": 789,
        "tenant_id": 1
    }
}
```

---

## 五、权限检查流程

### 5.1 RbacService::check()

```php
public static function check(string $permission): bool
{
    $user = auth()->user();
    if (!$user) return false;

    $tenantId = TenantContext::getId();

    // 路径 1: 查 operator_tenants
    $operatorTenant = DB::table('operator_tenants')
        ->where('user_id', $user->user_id)
        ->where('tenant_id', $tenantId)
        ->where('is_active', true)
        ->first();

    if ($operatorTenant) {
        if ($operatorTenant->role_id) {
            return static::checkRolePermission($operatorTenant->role_id, $permission);
        }
        return static::checkByRoleName($operatorTenant->role, $permission);
    }

    // 路径 2: 查 tenant_users（遗留兼容）
    $tenantUser = DB::table('tenant_users')
        ->where('user_id', $user->user_id)
        ->where('tenant_id', $tenantId)
        ->where('is_active', true)
        ->first();

    if ($tenantUser) {
        if ($tenantUser->role_id) {
            return static::checkRolePermission($tenantUser->role_id, $permission);
        }
        return static::checkByRoleName($tenantUser->role, $permission);
    }

    return false;
}
```

### 5.2 CheckPermission 中间件

```php
// admin 域：查 operators 表
protected function checkAdminAccess($user, $role): Response
{
    $operator = Operator::where('user_id', $user->user_id)
        ->where('scope', 'platform')
        ->where('is_active', true)
        ->first();

    if (!$operator) {
        return $this->forbidden('仅平台运营人员可访问');
    }

    return $next($request);
}

// console 域：查 operator_tenants
protected function checkConsoleAccess($user, $tenantId, $role): Response
{
    $operatorTenant = OperatorTenant::where('user_id', $user->user_id)
        ->where('tenant_id', $tenantId)
        ->where('is_active', true)
        ->first();

    if (!$operatorTenant) {
        return $this->forbidden('无权访问此租户后台');
    }

    TenantContext::setTenantRole($operatorTenant->role);
    return $next($request);
}
```

---

## 六、边界场景

### 6.1 Operator 同时也是普通用户

**场景**：张三是平台运营（operator），同时在租户 A 有个人账号（end_user）。

**处理**：
- operators 表：张三的 operator 记录
- users 表：张三在租户 A 的 user 记录（tenant_id=A）
- operator_tenants：张三在租户 A 的映射（user_id 指向同一个 user）

**关键**：operator_tenants.user_id 指向的 user 就是他在该租户的身份。

### 6.2 Operator 被降级

**场景**：张三从租户 A 的 tenant_admin 降为 member。

**处理**：
1. 更新 operator_tenants 的 role 字段
2. 撤销该 user 的所有 Sanctum tokens（强制重新登录）
3. 记录审计日志

### 6.3 Operator 被移除

**场景**：租户 A 移除 operator 张三。

**处理**：
1. operator_tenants.is_active = FALSE
2. users 表：该 user 的 is_active = FALSE
3. 撤销该 user 的所有 Sanctum tokens
4. operators 记录保留（可能管理其他租户）

### 6.4 租户删除

**场景**：租户 A 被删除。

**处理**：
1. 级联删除 operator_tenants 中 tenant_id=A 的记录
2. 级联删除 users 中 tenant_id=A 的记录
3. operators 记录保留（可能管理其他租户）

### 6.5 Operator 被禁用

**场景**：平台禁用某个 operator。

**处理**：
1. operators.is_active = FALSE
2. 该 operator 的所有 operator_tenants 映射失效
3. 撤销所有相关 user 的 tokens
4. 该 operator 无法登录任何租户

### 6.6 同一邮箱冲突

**场景**：`user@company.com` 在租户 A 是普通用户，又被邀请为租户 B 的 operator。

**处理**：
- users 表：(tenant_id=A, email=user@company.com) — 普通用户
- operators 表：email=user@company.com — 运营人员
- operator_tenants：(operator_id, tenant_id=B, user_id=xxx) — 在租户 B 的映射
- 两个体系独立，不会冲突

---

## 七、Operator 人才市场（未来扩展）

### 7.1 概念

基于 operators 表的全局唯一性，可以构建"运营人员人才市场"：

1. 运营人员注册成为平台认证 operator
2. 填写技能、经验、认证
3. 租户浏览/搜索 operator
4. 租户邀请 operator 管理自己的系统
5. 评价、评级、推荐系统

### 7.2 数据模型扩展

```sql
ALTER TABLE operators ADD COLUMN bio TEXT;
ALTER TABLE operators ADD COLUMN skills JSON;
ALTER TABLE operators ADD COLUMN certifications JSON;
ALTER TABLE operators ADD COLUMN rating DECIMAL(3,2);
ALTER TABLE operators ADD COLUMN total_ratings INT DEFAULT 0;
ALTER TABLE operators ADD COLUMN is_verified BOOLEAN DEFAULT FALSE;
ALTER TABLE operators ADD COLUMN is_available BOOLEAN DEFAULT TRUE;

CREATE TABLE operator_reviews (
    review_id BIGINT UNSIGNED PRIMARY KEY,
    operator_id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    reviewer_id BIGINT UNSIGNED NOT NULL,
    rating TINYINT NOT NULL,
    comment TEXT,
    created_at TIMESTAMP,
    FOREIGN KEY (operator_id) REFERENCES operators(operator_id),
    FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id)
);
```

### 7.3 商业价值

1. **平台化**：从"租户自己找运营"变成"平台提供运营资源"
2. **质量保证**：通过认证、评价系统保证 operator 质量
3. **规模化**：1000 个租户可以共享 100 个专业 operator
4. **商业模式**：平台可以从 operator 服务中抽成

---

## 八、系统初始化

### 8.1 初始化流程

```
系统首次部署时的完整初始化流程：

1. 运行数据库迁移
   └── 创建所有表（users, operators, operator_tenants, roles, permissions, tenants...）

2. 运行 Seeder（PlatformInitSeeder）
   ├── 创建平台默认租户（tenant_id = 9007199254740991）
   ├── 创建系统角色（super_admin, platform_admin, platform_support, tenant_admin...）
   ├── 创建权限节点（42 个）
   ├── 创建角色-权限映射
   ├── 创建超级管理员 operator（scope=platform, is_active=true）
   ├── 创建超级管理员在平台租户的 user
   └── 创建 operator_tenants 映射（operator → 平台租户 → user → super_admin）

3. 运行 Artisan 命令（首次设置）
   └── php artisan operator:set-password --email=sysop@mynet.club
       └── 设置超级管理员的密码
```

### 8.2 初始化数据

#### 平台默认租户

```php
// PlatformTenantSeeder
Tenant::updateOrCreate(
    ['tenant_id' => 9007199254740991],
    [
        'name' => '平台默认租户',
        'slug' => 'platform',
        'status' => 'active',
        'subscription_plan' => 'free',
        'is_platform_default' => true,
        'description' => '平台运营人员默认租户',
    ]
);
```

#### 系统角色

```php
// RoleSeeder
$roles = [
    // 平台级
    ['name' => 'super_admin', 'scope' => 'platform', 'display_name' => '超级管理员', 'is_system' => true],
    ['name' => 'platform_admin', 'scope' => 'platform', 'display_name' => '平台运营', 'is_system' => true],
    ['name' => 'platform_support', 'scope' => 'platform', 'display_name' => '平台客服', 'is_system' => true],
    // 租户级
    ['name' => 'tenant_admin', 'scope' => 'tenant', 'display_name' => '租户管理员', 'is_system' => true],
    ['name' => 'member', 'scope' => 'tenant', 'display_name' => '成员', 'is_system' => true],
    ['name' => 'viewer', 'scope' => 'tenant', 'display_name' => '只读', 'is_system' => true],
    ['name' => 'order_manager', 'scope' => 'tenant', 'display_name' => '订单员', 'is_system' => true],
    ['name' => 'sales', 'scope' => 'tenant', 'display_name' => '销售员', 'is_system' => true],
    ['name' => 'marketing', 'scope' => 'tenant', 'display_name' => '市场推广员', 'is_system' => true],
    ['name' => 'support_agent', 'scope' => 'tenant', 'display_name' => '售后客服', 'is_system' => true],
    ['name' => 'finance', 'scope' => 'tenant', 'display_name' => '财务', 'is_system' => true],
    ['name' => 'analyst', 'scope' => 'tenant', 'display_name' => '数据分析员', 'is_system' => true],
    // 普通用户
    ['name' => 'end_user', 'scope' => 'user', 'display_name' => '终端用户', 'is_system' => true],
];
```

#### 超级管理员 Operator

```php
// SuperAdminSeeder
$operator = Operator::create([
    'email' => 'sysop@mynet.club',
    'name' => '系统管理员',
    'password' => null,  // 首次登录时设置
    'scope' => 'platform',
    'is_active' => true,  // 系统初始化的 operator 直接激活
    'email_verified_at' => now(),
]);

$user = User::create([
    'tenant_id' => 9007199254740991,
    'email' => 'sysop@mynet.club',
    'name' => '系统管理员',
    'password' => null,  // 密码存在 operators 表
    'is_active' => true,
    'email_verified_at' => now(),
]);

OperatorTenant::create([
    'operator_id' => $operator->operator_id,
    'tenant_id' => 9007199254740991,
    'user_id' => $user->user_id,
    'role' => 'super_admin',
    'role_id' => $superAdminRoleId,
    'is_active' => true,
    'accepted_at' => now(),
]);
```

### 8.3 初始化 Artisan 命令

```php
// app/Console/Commands/PlatformInitCommand.php
class PlatformInitCommand extends Command
{
    protected $signature = 'platform:init 
                            {--email= : 超级管理员邮箱} 
                            {--password= : 超级管理员密码}';

    protected $description = '初始化平台（首次部署时运行）';

    public function handle()
    {
        // 1. 检查是否已初始化
        if (Operator::where('scope', 'platform')->exists()) {
            $this->error('平台已初始化，如需重置请先清空数据');
            return 1;
        }

        // 2. 运行 seeder
        $this->call('db:seed', ['--class' => 'PlatformInitSeeder']);

        // 3. 设置超级管理员密码
        $email = $this->option('email') ?? 'sysop@mynet.club';
        $password = $this->option('password') ?? $this->secret('请输入超级管理员密码');

        $operator = Operator::where('email', $email)->first();
        $operator->update([
            'password' => Hash::make($password),
        ]);

        // 同步到 user 表
        $user = User::where('email', $email)
            ->where('tenant_id', 9007199254740991)
            ->first();
        $user->update(['password' => Hash::make($password)]);

        $this->info('平台初始化完成！');
        $this->info('超级管理员邮箱: ' . $email);
        $this->info('请访问 adm.mynet.club 登录');

        return 0;
    }
}
```

### 8.4 租户初始化流程

```
新租户创建时的自动初始化：

1. 创建租户记录
2. 自动创建 tenant_admin 角色（如果不存在）
3. 创建租户管理员 user（tenant_id = 新租户）
4. 创建或复用 operator（租户管理员的邮箱）
5. 创建 operator_tenants 映射（role = tenant_admin）
6. 发送邀请邮件（设置密码）

// TenantService::create()
public function create(array $data): Tenant
{
    $tenant = Tenant::create($data);

    // 创建租户管理员
    $adminEmail = $data['admin_email'];
    
    // 检查 operator 是否存在
    $operator = Operator::where('email', $adminEmail)->first();
    if (!$operator) {
        $operator = Operator::create([
            'email' => $adminEmail,
            'name' => $data['admin_name'] ?? $adminEmail,
            'scope' => 'tenant',
            'is_active' => false,  // 邀请时未激活
            'invite_token' => Str::random(60),
            'invite_expires_at' => now()->addDays(7),
        ]);
    }

    // 创建 user
    $user = User::create([
        'tenant_id' => $tenant->tenant_id,
        'email' => $adminEmail,
        'name' => $data['admin_name'] ?? $adminEmail,
        'is_active' => false,
    ]);

    // 创建 operator_tenants 映射
    OperatorTenant::create([
        'operator_id' => $operator->operator_id,
        'tenant_id' => $tenant->tenant_id,
        'user_id' => $user->user_id,
        'role' => 'tenant_admin',
        'is_active' => true,
        'invited_at' => now(),
    ]);

    // 发送邀请邮件
    dispatch(new SendOperatorInviteJob($operator->operator_id, $tenant->tenant_id));

    return $tenant;
}
```

### 8.5 初始化脚本整合

```php
// database/seeders/PlatformInitSeeder.php
class PlatformInitSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('开始平台初始化...');

        // 1. 创建平台默认租户
        $this->call(PlatformTenantSeeder::class);

        // 2. 创建系统角色
        $this->call(RoleSeeder::class);

        // 3. 创建权限节点
        $this->call(PermissionSeeder::class);

        // 4. 创建角色-权限映射
        $this->call(RolePermissionSeeder::class);

        // 5. 创建超级管理员
        $this->call(SuperAdminSeeder::class);

        $this->command->info('平台初始化完成！');
    }
}
```

### 8.6 迁移现有数据

```php
// database/migrations/2026_07_14_000004_migrate_existing_operators.php
class MigrateExistingOperators extends Migration
{
    public function up(): void
    {
        // 1. 将 users.role = 'super_admin' 的用户创建为 operators
        $superAdmins = DB::table('users')
            ->where('role', 'super_admin')
            ->get();

        foreach ($superAdmins as $user) {
            // 创建 operator
            $operatorId = app(IdGeneratorContract::class)->generate();
            DB::table('operators')->insert([
                'operator_id' => $operatorId,
                'email' => $user->email,
                'name' => $user->name,
                'password' => $user->password,  // 迁移密码
                'scope' => 'platform',
                'is_active' => true,
                'email_verified_at' => $user->email_verified_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 创建 operator_tenants 映射
            DB::table('operator_tenants')->insert([
                'operator_id' => $operatorId,
                'tenant_id' => 9007199254740991,
                'user_id' => $user->user_id,
                'role' => 'super_admin',
                'is_active' => true,
                'accepted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. 将 tenant_users.role = 'tenant_admin' 的用户创建为 operators
        $tenantAdmins = DB::table('tenant_users')
            ->where('role', 'tenant_admin')
            ->get();

        foreach ($tenantAdmins as $tenantUser) {
            $user = DB::table('users')
                ->where('user_id', $tenantUser->user_id)
                ->first();

            if (!$user) continue;

            // 检查 operator 是否已存在
            $operator = DB::table('operators')
                ->where('email', $user->email)
                ->first();

            if (!$operator) {
                $operatorId = app(IdGeneratorContract::class)->generate();
                DB::table('operators')->insert([
                    'operator_id' => $operatorId,
                    'email' => $user->email,
                    'name' => $user->name,
                    'password' => $user->password,
                    'scope' => 'tenant',
                    'is_active' => true,
                    'email_verified_at' => $user->email_verified_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $operatorId = $operatorId;
            } else {
                $operatorId = $operator->operator_id;
            }

            // 创建 operator_tenants 映射
            DB::table('operator_tenants')->insert([
                'operator_id' => $operatorId,
                'tenant_id' => $tenantUser->tenant_id,
                'user_id' => $tenantUser->user_id,
                'role' => 'tenant_admin',
                'is_active' => true,
                'accepted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
```

### 8.7 初始化验证

```bash
# 验证初始化是否成功
php artisan platform:verify

# 检查项：
# ✅ 平台租户存在（tenant_id = 9007199254740991）
# ✅ 系统角色已创建（13 个）
# ✅ 权限节点已创建（42 个）
# ✅ 超级管理员 operator 存在
# ✅ 超级管理员 user 存在
# ✅ operator_tenants 映射正确
```

---

## 九、实施计划

### 阶段 1：数据库层（零破坏性）

#### Task 1.0: 重构初始化 Seeder

**Files:**
- Modify: `database/seeders/DatabaseSeeder.php`
- Modify: `database/seeders/PlatformTenantSeeder.php`
- Create: `database/seeders/RoleSeeder.php`
- Create: `database/seeders/PermissionSeeder.php`
- Create: `database/seeders/RolePermissionSeeder.php`
- Create: `database/seeders/SuperAdminSeeder.php`
- Create: `database/seeders/PlatformInitSeeder.php`
- Create: `app/Console/Commands/PlatformInitCommand.php`

**Steps:**
- [ ] 创建 PlatformInitSeeder（整合所有初始化 seeder）
- [ ] 创建 RoleSeeder（13 个系统角色）
- [ ] 创建 PermissionSeeder（42 个权限节点）
- [ ] 创建 RolePermissionSeeder（角色-权限映射）
- [ ] 创建 SuperAdminSeeder（超级管理员 operator + user + 映射）
- [ ] 创建 PlatformInitCommand（artisan 命令）
- [ ] 修改 DatabaseSeeder 调用 PlatformInitSeeder
- [ ] 运行 `php artisan migrate --seed` 验证

#### Task 1.1: 创建 operators 表

**Files:**
- Create: `database/migrations/2026_07_14_000001_create_operators_table.php`

**Steps:**
- [ ] 创建 operators 表（operator_id, email, name, password, scope, is_active, invite_token, invite_expires_at）
- [ ] 添加索引：email UNIQUE, scope, is_active
- [ ] 运行迁移验证

#### Task 1.2: 创建 operator_tenants 表

**Files:**
- Create: `database/migrations/2026_07_14_000002_create_operator_tenants_table.php`

**Steps:**
- [ ] 创建 operator_tenants 表
- [ ] 添加外键：operator_id → operators, tenant_id → tenants, user_id → users
- [ ] 添加唯一约束：(operator_id, tenant_id)
- [ ] 运行迁移验证

#### Task 1.3: 添加 platform 级角色

**Files:**
- Create: `database/migrations/2026_07_14_000003_add_platform_roles.php`

**Steps:**
- [ ] 新增 platform_admin 和 platform_support 系统角色
- [ ] 为 platform_admin 分配权限
- [ ] 为 platform_support 分配只读权限

### 阶段 2：模型层

#### Task 2.1: 创建 Operator 模型

**Files:**
- Create: `src/Models/Operator.php`

**Steps:**
- [ ] 创建 Operator 模型
- [ ] 定义 fillable, casts, 关系（tenants, users）
- [ ] 添加方法：isPlatform(), isTenant(), getTenantRole()

#### Task 2.2: 创建 OperatorTenant 模型

**Files:**
- Create: `src/Models/OperatorTenant.php`

**Steps:**
- [ ] 创建 OperatorTenant 模型
- [ ] 定义关系：operator, tenant, user
- [ ] 添加 BelongsToTenant trait

#### Task 2.3: 修改 User 模型

**Files:**
- Modify: `src/Models/User.php`

**Steps:**
- [ ] 添加 tenant_id 到 fillable
- [ ] 移除 role 到 fillable（暂时保留，兼容期）
- [ ] 添加 operator() 关系（通过 operator_tenants）

#### Task 2.4: 修改 TenantUser 模型

**Files:**
- Modify: `src/Models/TenantUser.php`

**Steps:**
- [ ] 标记 role 字段为 deprecated
- [ ] 确保 role_id 为首选权限来源

### 阶段 3：服务层

#### Task 3.1: 创建 OperatorService

**Files:**
- Create: `src/Services/OperatorService.php`

**Steps:**
- [ ] invite(email, tenantId, role) — 邀请 operator
- [ ] acceptInvite(token, password) — 接受邀请
- [ ] addToTenant(operatorId, tenantId, role) — 添加到租户
- [ ] removeFromTenant(operatorId, tenantId) — 从租户移除
- [ ] updateRole(operatorId, tenantId, role) — 更新角色
- [ ] listByTenant(tenantId) — 列出租户的 operator
- [ ] listTenants(operatorId) — 列出 operator 管理的租户

#### Task 3.2: 重构 RbacService

**Files:**
- Modify: `src/Services/RbacService.php`

**Steps:**
- [ ] 修改 check() 方法：先查 operator_tenants，再查 tenant_users
- [ ] 移除 `$user->role === 'super_admin'` bypass
- [ ] 统一走 role_id → role_permissions
- [ ] 保留 checkByRoleName() 作为遗留兼容

### 阶段 4：中间件层

#### Task 4.1: 创建 IdentifyOperator 中间件

**Files:**
- Create: `src/Middleware/IdentifyOperator.php`

**Steps:**
- [ ] 识别请求是否来自 operator（admin/console 域名）
- [ ] 从 operators 表获取当前 operator
- [ ] 设置 TenantContext

#### Task 4.2: 重构 CheckPermission

**Files:**
- Modify: `src/Middleware/CheckPermission.php`

**Steps:**
- [ ] checkAdminAccess()：查 operators 表
- [ ] checkConsoleAccess()：查 operators 表 + operator_tenants
- [ ] checkTenantAccess()：查 users 表 + tenant_users
- [ ] 移除所有 `$user->role` 检查

### 阶段 5：控制器层

#### Task 5.1: 创建 OperatorController

**Files:**
- Create: `src/Modules/Auth/Http/Controllers/OperatorController.php`

**Steps:**
- [ ] invite() — 邀请 operator
- [ ] acceptInvite() — 接受邀请
- [ ] CRUD endpoints for operators
- [ ] 租户分配/移除 endpoints
- [ ] 角色更新 endpoints

#### Task 5.2: 重构 AuthController

**Files:**
- Modify: `src/Modules/Auth/Http/Controllers/AuthController.php`

**Steps:**
- [ ] adminLogin()：查 operators 表
- [ ] consoleLogin()：查 operators 表 + operator_tenants
- [ ] login()：查 users 表（不变）
- [ ] userToArray()：根据来源返回不同字段

### 阶段 6：数据迁移

#### Task 6.1: 迁移现有数据

**Files:**
- Create: `database/migrations/2026_07_14_000004_migrate_existing_operators.php`

**Steps:**
- [ ] 将 users.role = 'super_admin' 的用户创建为 operators
- [ ] 创建 operator_tenants 映射（关联到平台租户）
- [ ] 为现有 tenant_admin 用户创建 operators（可选）
- [ ] 验证数据完整性

### 阶段 7：清理

#### Task 7.1: 移除遗留代码

**Files:**
- Modify: 多个文件

**Steps:**
- [ ] 移除 users.role 字段
- [ ] 移除 tenant_users.role 字符串字段
- [ ] 移除 CheckPermission 中的 ROLE_* 常量
- [ ] 移除 RbacService::checkByRoleName()
- [ ] 运行全量测试

---

## 九、总结

### 设计优势

| 优势 | 说明 |
|------|------|
| 职责分离 | 身份(users)、管理(operators)、映射(operator_tenants) 三层清晰 |
| 安全隔离 | 普通用户完全隔离，operator 通过授权访问 |
| 邀请制 | 安全、标准、用户体验好 |
| 灵活扩展 | 支持人才市场、委托管理等高级功能 |
| 审计友好 | 完整的权限变更记录 |
| 平台化 | 支持 1000+ 租户的规模化运营 |

### 关键风险

| 风险 | 等级 | 缓解措施 |
|------|------|---------|
| 数据迁移复杂 | 高 | 分阶段迁移，保留兼容期 |
| 登录流程变化 | 高 | 通过域名区分，保持用户体验 |
| 前端兼容 | 中 | API 保持兼容，渐进迁移 |
| 性能影响 | 低 | 索引优化，缓存 role_permissions |

### 下一步

1. 确认最终设计
2. 开始阶段 1（数据库层 + 初始化）
3. 逐步实施，每阶段验证
4. 完成后运行 `php artisan platform:init` 验证初始化流程
