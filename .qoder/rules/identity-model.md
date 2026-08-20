---
trigger: always_on
alwaysApply: true
---

# 身份模型铁律（生成代码时必须遵守）

本规则定义全系统唯一的身份模型，杜绝 end_user/customer 等误导别名制造"第三/第四种人"假象。

## 1. 系统只有两种「人」（可认证身份实体）

| 身份 | 存储 | 基类 | 定义 |
|---|---|---|---|
| **Operator** | `operators` 表 | `Authenticatable` | 登录后台运营的人。`scope`=platform（平台运营，进 admin 后台）/tenant（租户运营，进 console 后台）。经 `operator_tenants` 关联租户 |
| **User** | `users` 表 | `Authenticatable` | 被服务的用户。可开放注册，经 `tenant_users` 关联租户，访问前台 /app |

权限解析见 `src/Modules/Auth/Http/Middleware/CheckPermission.php`：`$request->user()` 只可能是 Operator 或 User，无第三种身份。

## 2. 角色（roles 表）≠ 身份

- `roles` 表是角色字典：`tenant_admin`（管理员）/ `member`（成员）。
- Operator（经 `operator_tenants.role_id`）与 User（经 `tenant_users.role_id`）**共用**这套角色。
- **`end_user` 是历史误命名**：它只是"成员"角色，却叫 end_user，与 User 实体概念混淆（一个 Operator 也能拥有 end_user 角色，足见它不是"终端用户身份"）。已统一改名为 **`member`**。新代码禁止再使用 `end_user` 字面量，一律用 `member`。

## 3. customer 不是框架身份概念

- `customer` 是下游 SCRM 项目的「客户档案记录」，**非身份实体**（不继承 Authenticatable）。
- 框架层不得引入 customer 作为身份；涉及"被服务的人"一律用 **User**（user_id）。

## 4. 铁律

- 新增身份相关代码，只允许 Operator / User 两种实体。
- 禁止新增 `end_user` 字面量（用 `member`）；禁止在框架层引入 customer 身份。
- 角色名引用统一走 `roles` 表查询，不硬编码字符串。
