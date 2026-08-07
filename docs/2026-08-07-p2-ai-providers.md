# P2: ai_providers 多源管理表接线

**会话时间**: 2026-08-07（P1 后续会话）  
**范围**: 框架 multi_tenant_saas → neihang.com 生产部署  
**状态**: ✅ 已完成并验证  
**前置**: [P1 配置后台化](./2026-08-07-p1-config-admin-console.md)（遗留项「ai_providers 多源管理表未接线」）

---

## 目标

将框架已有但零接线的 `ai_providers` 表（`AiProvider` 模型，Crypt 加密 api_key，tenant_id 可空）
接入平台配置解析链与 admin 后台，实现提供商连接的**结构化多源管理**。

**核心设计**：提供商连接三级解析（`AiPlatformConfigService::resolveProviderConfig()` 单一收口，
`AiGatewayService` / `AiTextService` / `AiConfigService` / `AiModelCatalogService` / `BailianImageProvider` 自动继承）：

```
ai_providers 表（系统级 tenant_id=null 且 active）
    → system_settings 覆盖组（group='ai_provider_{code}'，P1 兼容层）
    → config/env 引导层
```

---

## 实施清单

### 模型层 ✅
- `AiProvider` 覆写 `bootBelongsToTenant()`（同 `MailTemplate` 先例）：
  - 查询：租户上下文下可见当前租户覆盖 + 系统级配置
  - 创建：**不自动填充 tenant_id**——显式 null 即系统级记录（默认 trait 会用当前租户覆盖，
    导致 admin 创建的记录错误绑定租户）
  - api_key Crypt 加密 mutator 保留不变

### 服务层 ✅
- `AiPlatformConfigService::providerRecord()` 新增：系统级 + active 记录查询，Cache 60s TTL，
  表不存在等异常静默降级为 null
- `resolveProviderConfig()` 接入 ai_providers 最高优先级；双字段变体写入（url/base_url、key/api_key）
- `forgetCached()` 同时失效 provider_record 缓存

### 接口层 ✅
- `AdminAiController` 新增 providers CRUD（沿用 setting.view / setting.update 权限）：
  - `GET /admin/ai/providers` — 系统级列表（priority 排序，api_key 掩码）
  - `POST /admin/ai/providers` — 创建（code 正则 `^[a-z0-9_]+$` + 系统级内唯一）
  - `PUT /admin/ai/providers/{id}` — 更新（`********`/空值掩码回存跳过，同 P1 安全模式）
  - `DELETE /admin/ai/providers/{id}`
- `providerTest()` 来源检测升级：ai_providers 记录或 system_settings 覆盖均报 `source=db`
- `presentProvider()` 手工序列化：api_key 永不出库（有值返回掩码，无值空串），
  不走 `toArray()` 避免触发解密 mutator

### 前端 ✅
- `Ai/resources/admin/ui/element-plus/views/AiSettings.vue`：新增「提供商」tab（表格 + 编辑弹窗，
  password 输入框 + 掩码提示）
- `Ai/resources/admin/ui/bootstrap/views/AiSettings.vue`：同步新增（表格 + 内联表单）
- 两版「连接测试」tab 提示文案同步三级链说明

### 测试 ✅
- `tests/Schema/AiModule.php`：补 `ai_providers` 建表（索引名加表前缀防 SQLite 全局命名冲突）
- `AiPlatformConfigServiceTest.php` +3：
  - ai_providers 优先级高于 system_settings 与 env（双字段 + 其余字段保留）
  - inactive 记录不参与解析，回退 system_settings
  - api_key 落库加密、解密后正常参与解析
- `AdminAiControllerTest.php` +3：
  - CRUD 生命周期（掩码返回 / tenant_id=null 系统级落库 / 重复与非法 code 422 / 掩码回存不覆盖真实密钥 / 删除）
  - 未知 provider 更新/删除 404
  - 仅 ai_providers 记录时连接测试 `source=db`（Http::fake + Bearer 断言）

---

## 测试结果

| 测试集 | 结果 |
|---|---|
| `AiPlatformConfigServiceTest` + `AdminAiControllerTest` | 20 passed (82 assertions) |
| filter `AiConfig\|AiGateway\|AiText\|AiModelCatalog\|AiProvider` | 93 passed (197 assertions)，EXIT=0 |

---

## 关键架构决策

1. **boot 覆写而非改 BelongsToTenant trait**：默认 trait 的 creating 钩子会用当前租户覆盖
   显式 null（`is_null` 判断），改 trait 影响全部租户模型（基础设施级变更）；
   采用 `MailTemplate` 已验证的模型级覆写先例，零波及
2. **ai_providers 优先于 system_settings**：结构化多源管理表是正式方案，
   system_settings 覆盖组降级为 P1 兼容层；inactive 记录直接跳过
3. **本期仅系统级（tenant_id=null）**：租户级覆盖记录表结构已支持，
   解析链（静态、无租户上下文依赖）暂不读取，留待后续
4. **failover 未做**：`AiProvider` 注释提及"供 AiGatewayService 故障转移参考"，
   本期仅接线配置解析与管理；priority 字段已落库备用

---

## 部署验证 ✅ (neihang.com, 192.168.100.11)

- 框架 push → split（38 包：1 根包 + 37 模块，44s）→ scrm-platform `composer update`（36 个 dsplat 包）→ `deploy.py incremental`
- admin SPA 重建（element-plus）→ `rsync public/admin/`
- 全链路验证通过（platform Operator token，验证后已吊销）：
  - `GET /admin/ai/providers` — 空列表正常；创建后 api_key 掩码 `********`
  - `POST /admin/ai/providers` — 系统级落库（tenant_id=null），api_key Crypt 加密
  - `PUT`（掩码回存）— 落库解密仍为原密钥，改名生效
  - 连接测试三级链实证（bailian）：
    - 无 DB 记录 → `source=env`，236 models
    - 创建覆盖记录（假 key）→ 即时 `source=db`，base_url 切换，401（缓存失效即时生效）
    - 删除覆盖记录 → 即时回退 `source=env`，236 models
  - `DELETE` 清理 + P1 回归 `GET /defaults` 正常（qwen3.7-plus / bailian）

---

## 遗留 / 后续

- **租户级 provider 覆盖**（tenant_id 非 null）：表结构就绪，解析链未接入
- **AiGatewayService 故障转移**：按 priority/status 做多源 failover

---

## 涉及文件清单

### 框架 (multi_tenant_saas) — 已提交 (0c7e83e)
```
M  src/Modules/Ai/Models/AiProvider.php
M  src/Modules/Ai/Services/AiPlatformConfigService.php
M  src/Modules/Ai/Http/Controllers/AdminAiController.php
M  src/Modules/Ai/Routes/admin.php
M  src/Modules/Ai/resources/admin/ui/element-plus/views/AiSettings.vue
M  src/Modules/Ai/resources/admin/ui/bootstrap/views/AiSettings.vue
M  tests/Schema/AiModule.php
M  tests/AiPlatformConfigServiceTest.php
M  tests/AdminAiControllerTest.php
```

### 下游 (scrm-platform) — 已提交 (2c0a926)
```
M  composer.lock（36 个 dsplat 包）
```
