---
module: api-token
audience: operator
locale: zh
facts_checksum: 1216f5dd433bf121d234f503f480b103728d57f6bea202d04db5912bb6670187
generated_by: secretary:kb:build
---

# API Token 使用手册

## 模块简介

API Token 管理模块用于为租户提供与 New API 后端对接的 API 接入能力。通过该模块，运营人员可为租户创建、查询、禁用或轮换 API Token，管理 Token 的权限范围（abilities）、模型白名单及配额（quota），并支持对 Token 配额进行充值和同步。

本模块解决的核心问题包括：  
- 租户需安全地接入外部 API 服务，但缺乏统一的 Token 管理机制；  
- 运营需要对特定租户的 API 使用情况进行控制与监控；  
- 支持按需扩展 Token 权限、调整使用限制、追踪调用行为。

模块具备租户级开关功能，可根据业务需求灵活启用或关闭。

---

## 核心功能

### 创建与管理 API Token

运营人员可通过系统接口为指定租户创建新的 API Token。创建时可设定 Token 的权限范围（abilities）和有效期。Token 生成后将绑定至对应租户，并记录其初始状态与使用信息。

支持以下操作：
- 为租户创建新 Token；
- 查询租户下所有已创建的 Token 列表；
- 删除指定 Token（永久移除）；
- 查看 Token 的可用权限列表（abilities）。

### 轮换与禁用 Token

当存在安全风险或需更换密钥时，可执行 Token 轮换操作。系统将生成新的 Token（sk-xxx 形式），并自动更新本地缓存，旧 Token 将被标记为失效。

同时支持手动禁用某个 Token，使其立即无法使用，适用于异常或违规场景。

### 配额管理与充值

运营人员可向租户的 API Token 追加使用配额（quota）。该操作会触发与 New API 后端的通信，完成配额注入。  
系统还支持从 New API 拉取最新的配额数据，确保本地缓存与实际用量一致。

### 模型白名单与使用限制调整

可对 Token 的可用模型范围进行配置，仅允许其调用指定模型。支持动态调整模型访问权限，实现精细化管控。

### 日志与用量监控

系统支持获取指定 Token 在某一天的调用用量汇总，以及拉取详细的调用日志（如请求参数、响应结果、耗时、IP 地址等），便于审计与故障排查。

---

## 常见操作流程

### 1. 为租户创建 API Token

1. 确认目标租户的 `tenantId`；
2. 调用 `/tenants/{tenantId}/api-tokens` 接口，发送 POST 请求；
3. 请求中包含所需权限（abilities）及可选的有效期设置；
4. 接口返回新生成的 Token 信息，包括 `token` 和 `token_plain`（明文密钥）；
5. 将 `token_plain` 安全交付给租户，后续由租户在调用 New API 时使用。

> 注：若未指定有效期，则使用默认过期时间（由配置项决定）。

### 2. 查询租户下的所有 Token

1. 获取目标租户的 `tenantId`；
2. 发送 GET 请求至 `/tenants/{tenantId}/api-tokens`；
3. 返回该租户下所有 Token 的列表，包含状态、权限、最后使用时间等信息。

### 3. 禁用或删除 Token

1. 获取要操作的 Token 的 `tokenId` 及所属租户的 `tenantId`；
2. 发送 DELETE 请求至 `/tenants/{tenantId}/api-tokens/{tokenId}`；
3. 系统将永久移除该 Token，其后续调用将被拒绝。

> 注意：此操作不可逆，请谨慎执行。

### 4. 轮换 Token 密钥

1. 确定需轮换的 Token 的 `tokenId` 和 `tenantId`；
2. 执行轮换操作（调用 `rotateToken()` 方法）；
3. 系统生成新密钥（sk-xxx），并更新本地记录；
4. 原 Token 自动失效，新密钥可用于后续调用。

### 5. 向 Token 充值配额

1. 确定目标 Token 的 `apisvr_token_id` 或关联的 `user_id` 与 `tenant_id`；
2. 调用 `topUpQuota()` 服务方法，传入充值数量；
3. 系统向 New API 后端发起请求，完成配额追加；
4. 配额变更后，系统将同步最新状态至本地缓存。

### 6. 同步 Token 配额数据

1. 触发定时任务或手动调用 `syncAllQuotas()`；
2. 系统遍历所有用户 Token，批量从 New API 拉取当前剩余配额与已用配额；
3. 更新本地 `remain_quota_cache` 与 `used_quota_cache` 字段；
4. 保证配额数据实时准确，支撑前端展示与风控判断。

### 7. 查看 Token 使用日志

1. 获取目标 Token 的 `tokenId` 及所属租户信息；
2. 调用 `fetchTokenLogs()` 方法，传入时间范围或分页参数；
3. 返回该 Token 的完整调用日志，包括请求参数、响应内容、耗时、来源 IP 与用户代理等字段；
4. 用于审计、分析或定位调用异常。

---

## 相关配置说明

模块运行依赖以下配置项，均位于 `config/apitoken.php` 文件中：

- **base_url**：New API 后端的基础地址，用于所有 API 通信；
- **admin_key**：管理员身份验证密钥，用于授权管理操作；
- **admin_user_id**：在调用 New API 时作为请求头中的用户标识；
- **timeout**：HTTP 请求超时时间（单位：秒）；
- **default_expired_time**：新建 Token 的默认过期时间（-1 表示永不过期）；
- **default_group**：Token 默认归属的分组名称；
- **enabled**：是否启用该模块，影响租户能否使用相关功能。

> 所有配置项均可通过环境变量覆盖，建议在生产环境中根据实际部署情况配置。
