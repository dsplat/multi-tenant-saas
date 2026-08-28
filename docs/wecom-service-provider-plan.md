# 企微服务商代开发模式框架实现计划

## 背景与目标

企微自建应用的可信域名受主体校验(备案主体须与企微认证主体一致),租户配置 auth.neihang.com 会被拒绝(蓝眼兔生产实锤)。唯一合法路径:平台注册企微服务商,走**代开发应用模式**——服务商创建代开发模板,租户超管扫码授权,平台拿到 permanent_code 后以服务商身份代跑应用,可信域名/回调域使用平台域。

本期只做企微(用户已确认),微信第三方平台(公众号)完全不动。服务商凭证用管理页 + 数据库表(用户已确认)。

## 章节状态速览(2026-08-28 review)

| 章节 | 状态 | 说明 |
|---|---|---|
| 一~八 | ✅ 已实施(2026-08-26~28 落地) | 模块骨架/套件服务(13 方法)/套件+应用回调/租户授权链路/登录双轨/admin 管理均已落地;五章登录双轨已实现,待 9.6 迁移 |
| 九 | ✅ 已实施(2026-08-28,阶段 A+B 完成=M2 里程碑) | 9.6 迁移→9.1 代理→9.2 互斥→9.4 驱动→9.3 ibot 双轨全部落地,实施记录见各节;阶段 C(11)待启动 |
| 十 | ✅ 决策已定稿 | 能力×付费模型(许可两类/拐点/边界/10.4 决议)作为十一章依据 |
| 十一 | ⏳ 待实施 | 套餐体系落地(能力包/配额/两端 UI),执行顺序见十二章 |

## 关键设计决策

- **新建 `src/Modules/WechatWork` 模块**(自包含范式):服务商凭证、suite 回调、代开发授权、corp_token 全在此模块;Auth 模块的 `WechatWorkOAuthService` 只做"凭证来源双轨"适配,通过容器解析 WechatWork 模块服务,与现有 `Auth → Infrastructure`(Tenant/TenantSetting)跨模块引用模式一致。
- **平台级凭证表参照 `ai_providers` 先例**:`tenant_id=null` 表示系统级,admin 后台管理,权限沿用 `rbac.permission:setting.view/update`,admin SPA 双套 UI(bootstrap + element-plus)同步实现。
- **回调加解密复用 `src/Support/WechatWork/WechatWorkCrypto`**(token/aesKey 验签 + 解密,套件回调协议同构)。
- **登录链路与自建应用兼容**:代开发模式中 permanent_code 充当 secret 角色,`qrConnect` 扫码链路不变,只需换 corp access_token 获取方式(service/get_corp_token)。

## 一、模块骨架与数据层

### 1. 新建模块 `src/Modules/WechatWork/`
- `WechatWorkServiceProvider.php` extends `ModuleServiceProvider`,`$moduleName = 'wechatwork'`;在 `bootModule()` 中额外 `loadRoutesFrom` 套件回调路由(参照 Domain 模块 `Routes/verify-file.php` 的注册方式)。
- 目录:`Http/Controllers/`、`Services/`、`Models/`、`Routes/`、`Database/migrations/`、`resources/admin/ui/{bootstrap,element-plus}/views/`。

### 2. 迁移 `Database/migrations/2026_08_26_000001_wechat_work_module.php`(原生 SQL 风格,主键 bigint unsigned + 注释「IdGenerator 全局ID」)

**`service_providers`(平台级,tenant_id=null,参照 ai_providers)**
- `service_provider_id` PK、`name` varchar(100)、`provider_corp_id` varchar(64) 服务商企业 ID
- `suite_id` varchar(64)、`suite_secret` text 加密存储
- `callback_token` varchar(255)、`encoding_aes_key` varchar(255)(模板回调 Token/EncodingAESKey)
- `callback_url` varchar(500)(模板回调 URL,平台域)、`status` varchar(20) active/inactive、`metadata` json

**`wechat_work_authorizations`(租户授权记录)**
- `authorization_id` PK、`tenant_id`、`service_provider_id`
- `corp_id` varchar(64)、`agent_id` varchar(64)、`permanent_code` text 加密存储(充当 secret)
- `status` varchar(20) pending/authorized/revoked、`authorized_at`/`revoked_at`
- UNIQUE(`tenant_id`)(一租户一条代开发授权)

### 3. 模型
- `ServiceProvider`(参照 `AiProvider`:覆写 `BelongsToTenant` boot,tenant_id=null 系统级;secret 字段 `$hidden` + 加密读写)
- `WechatWorkAuthorization`(标准租户模型:`HasGlobalId` + `BelongsToTenant` + `SerializesFriendlyDates`)

## 二、核心服务 `Services/WechatWorkSuiteService.php`

封装全部服务商 API(均走 `src/Support/WechatWork/WechatWorkApiClient` 同款 Http 调用 + parseResponse 风格):

| 方法 | 企微 API | 说明 |
|------|---------|------|
| `suiteTicket()/storeSuiteTicket()` | - | 回调写入 + 缓存读取,key `wechat_work_suite_ticket:{providerId}`,TTL 30 分钟 |
| `suiteAccessToken(provider)` | `service/get_suite_token` | 需最新 suite_ticket;缓存提前 5 分钟过期 |
| `providerAccessToken(provider)` | `service/get_provider_token` | 服务商 token(9.1 服务商接口,永不走代理)|
| `preAuthCode(provider)` | `service/get_pre_auth_code` | 预授权码,缓存 |
| `buildAuthorizeUrl(tenantId)` | `3rdapp/install`(+ `get_customized_auth_url`) | `suite_id + pre_auth_code + redirect_uri(平台回调域)+ state(带租户前缀,复用 ManagesOAuthState 风格)` |
| `exchangePermanentCode(provider, authCode)` | `service/get_permanent_code` | 返回 corp_id/permanent_code/agent_id |
| `corpAccessToken(tenantId)` | `service/get_corp_token` | permanent_code 换 corp_token,缓存提前 5 分钟过期;9.1 需加代理支持 |
| `templatePermissions()/testSuiteToken()/authorization()` | - | 模板权限标签 / 连接测试诊断 / 租户授权记录查询 |

✅ 本章已实施(2026-08-26~28);9.1 代理改造时对 provider 级方法做「永不走代理」隔离。

## 三、套件回调 `Http/Controllers/SuiteCallbackController.php`(公开,平台域)

路由 `GET/POST /api/v1/wechat-work/suite/callback`(挂在平台域 auth.neihang.com,无租户上下文,参照 Domain `verify-file.php` 公开路由):

- **GET**(URL 有效性验证):`msg_signature/timestamp/nonce/echostr` → 复用 `WechatWorkCrypto::verifyUrl()`,原样返回明文 echostr(企微要求纯文本)
- **POST**(事件推送):`WechatWorkCrypto::verifySignature()` 验签 → `decrypt()` 解密 XML → 按 `InfoType` 分发:
  - `suite_ticket`:写入缓存(每 10 分钟推送,必须及时更新,否则换 token 失败)
  - `create_auth`(授权成功):取 `CreateAuthInfo.auth_code` → `exchangePermanentCode()` → 写 `wechat_work_authorizations`(status=authorized)
  - `cancel_auth`(取消授权):按 `AuthCorpId` 标记 status=revoked,登录降级
  - 其他事件:`change_auth`/`contact_sync` 等打日志忽略

## 四、租户授权链路(console 端)

### 控制器 `Http/Controllers/TenantWechatWorkAuthController.php`(api 路由,租户中间件)

| 端点 | 方法 | 说明 |
|------|------|------|
| `GET /api/v1/wechat-work/authorize` | `authorize()` | 生成授权 URL → 302 跳企微 `3rdapp/install`(state 带 tenantId 前缀) |
| `GET /api/v1/wechat-work/callback` | `callback()` | 企微授权页跳回;校验 state → 从 auth_code 换 permanent_code → 入库(auth_code 由 SuiteCallbackController 或本端点处理,见下) |
| `GET /api/v1/wechat-work/status` | `status()` | 返回当前租户授权状态(corp_id/agent_id/status) |
| `POST /api/v1/wechat-work/revoke` | `revoke()` | 标记 revoked(企微侧在服务商后台/企业侧解绑,本端同步状态) |

**授权流程归属决策**:auth_code 换取 permanent_code 放 `SuiteCallbackController`(create_auth 事件内完成入库),租户侧 callback 仅做"授权完成"页面回跳展示;若租户 callback 带 `auth_code` 参数(企微 3rdapp 重定向也携带),则本端点兜底换取。两条路径都幂等 `updateOrCreate`。

### console UI:OAuthSettings.vue 企微 tab 增加「代开发应用授权」区块
- `src/Modules/Auth/resources/console/ui/bootstrap/views/OAuthSettings.vue` 与 `element-plus/views/OAuthSettings.vue` 同步改
- 未授权:显示「使用平台代开发应用扫码授权」按钮(调 `/wechat-work/authorize`);已授权:显示 corp_id/agent_id/状态 + 解除授权
- 保留原有自建应用表单(双轨兼容,帮助文案注明两种模式差异)

## 五、Auth 登录链路双轨适配

`src/Modules/Auth/Services/WechatWorkOAuthService.php`:

- `getConfig(int $tenantId)`:先经容器解析 `WechatWorkSuiteService`,查 `wechat_work_authorizations` 该租户是否 `authorized`;是 → 返回 `['corp_id' => 授权表 corp_id, 'agent_id' => 授权表 agent_id, 'secret' => permanent_code, 'mode' => 'suite']`;否 → 原 tenant_settings 流程(`mode='self'`)
- `getAccessToken(int $tenantId)`:`mode=suite` 时调 `WechatWorkSuiteService::corpAccessToken()`;`mode=self` 时走原 `gettoken`
- `getAuthorizeUrl()` / `handleCallback()` / `getUserIdentity()` 不变(扫码链路同构)
- `isConfigured()`:两种模式任一满足即视为已配置

## 六、平台 admin 管理 ✅ 已实施(2026-08-26~28,代码与本章一致)

### 控制器 `Http/Controllers/AdminServiceProviderController.php`(参照 `AdminAiController`)
- `providerIndex/providerStore/providerUpdate/providerDestroy`(掩码回显 secret,同 `API_KEY_MASK` 模式)
- `providerTest`:用 suite_id+suite_secret 实测 `get_suite_token`(需配置了 suite_ticket 才可能成功,返回诊断信息)
- `authorizations`:已授权租户列表(corp_id/租户名/状态)

### 路由 `Routes/admin.php`(参照 Ai 模块)
```php
Route::prefix('wechat-work')->group(function () {
    Route::middleware('rbac.permission:setting.view')->group(function () {
        Route::get('/providers', ...); Route::get('/providers/{id}', ...); Route::get('/authorizations', ...);
    });
    Route::middleware('rbac.permission:setting.update')->group(function () {
        Route::post('/providers', ...); Route::put('/providers/{id}', ...); Route::delete('/providers/{id}', ...);
        Route::post('/providers/{id}/test', ...);
    });
});
```

### admin UI:`resources/admin/ui/{bootstrap,element-plus}/views/ServiceProviderSettings.vue`(双套)
- 服务商凭证 CRUD 表单(suite_id/suite_secret/callback_token/encoding_aes_key/name)+ 掩码
- 回调 URL 展示(复制按钮)+ 帮助文案(认证 300 元/年、模板上线 15 分钟生效、测试企业 0 元联调、可信域名填 auth.neihang.com 并做 WW_verify 归属认证)
- 已授权租户列表

## 七、测试计划

按规则只跑受影响测试(新建文件单跑确认):

- `tests/WechatWorkSuiteServiceTest.php`:`Http::fake` 模拟 get_suite_token/get_pre_auth_code/get_permanent_code/get_corp_token;suite_ticket 缺失/过期时报错;token 缓存提前过期
- `tests/WechatWorkSuiteCallbackTest.php`:GET 验签 echostr 返回明文;POST suite_ticket 写缓存;create_auth 入库(auth_code 换 permanent_code);cancel_auth 标记 revoked;伪造签名 403
- `tests/WechatWorkAuthzTest.php`:租户授权状态查询/幂等写入;WechatWorkOAuthService 双轨凭证解析(mode=suite 时走 get_corp_token,未授权走原 tenant_settings)
- 回归:`tests/` 中现有 Auth/OAuth 相关测试(WechatWorkOAuthService 改动影响面)

## 八、部署与运维要点

- 框架推送即发布(split 自动拆包,新模块自动成为 `dsplat/multi-tenant-saas-module-wechatwork`);下游 scrm-platform 需 `composer update dsplat/*` 更新 lock 后 deploy.py incremental
- 平台侧需要的人工动作(不在代码内):注册企微服务商 + 认证(300 元/年,主体与 auth.neihang.com 备案一致)→ 创建代开发模板 → 可信域名填 auth.neihang.com(WW_verify 验证走已上线的 `VerificationFileController`)→ 模板回调 URL 填本系统 `https://auth.neihang.com/api/v1/wechat-work/suite/callback` → 上线审核 → 绑测试企业 0 元联调
- Nginx:auth.neihang.com 已在 server_name(勿动);套件回调路径走 PHP 动态服务,无额外 location 需求

## 假设

- 服务商认证由运营在线下完成(代码只提供凭证录入 + 测试连接),不做资质申请流程自动化
- 一套服务商凭证(单 provider),表结构预留多 provider 扩展;先不做多模板切换
- 代开发模式下扫码登录回调域默认平台统一回调域(OAUTH_CALLBACK_DOMAIN),与自建应用降级路径(租户自有域)互不影响

---

# 九、双体系隔离与完善计划(2026-08-28 追加,代码实现不砍项)

> 背景:企微代开发(suite)与自建(self)是两套接口与逻辑,已确认以下改造全部落地。

## 9.1 出口 IP 隔离:租户级代理(决策:替代 php-fpm 多实例方案)

**决策依据**:自建模式出口 IP 必须是租户自有 IP(企微判定供应商 IP 拒绝,蓝眼兔生产实锤);用户已确认方案 = 平台统一采购代理服务,每自建客户分配一个独立出口 IP(约 200-300 元/年/客户),**单实例单 DB 混跑,不需要多 php-fpm 实例、不需要实例级模式开关**。

**IP 刚性(2026-08-28 查证)**:企微开发者社区官方答复——**不支持多企业共用同一 IP**(一个 IP 只能给一个主体使用,防止服务商对接企业数据;集团关联企业需股权证明走人工工单)。因此自建出口 IP **一客户一 IP 无共享摊薄空间**,成本刚性;10.2 定价成立。

改动清单:

1. **tenant_settings 新配置 `wechatwork.proxy`**（JSON 结构，无迁移，整组加密存储）：`enabled / scheme(默认 http) / host / port / username / password / exit_ip`（与 9.6 配置组口径一致，不再单设 outbound_proxy 组）
2. **admin 租户管理页**：「企微出口代理」配置区（平台运营分配）+ **展示当前出口 IP**（客户需将代理 IP 加入企业后台可信 IP；后端 API 本轮完成，UI 双套随 11.4 租户管理页统一落地）
3. **框架 helper `WechatWorkProxy::resolve(int $tenantId): array`**（src/Support/WechatWork/，根包）：读 tenant_settings wechatwork.proxy → 返回 `['proxy' => 'http://user:pass@host:port']` 或 `[]`（直连）
4. **企业接口注入** `Http::withOptions($proxyOptions)`：
   - `WechatWorkApiClient`：构造加 `?string $proxy` 参数（默认 null），私有 `http()` 统一 21 处请求（超时 + 代理注入，含 media/upload 的 30s 超时）
   - `WechatWorkSuiteService::corpAccessToken`（已有 $tenantId 参数）
   - `WechatWorkOAuthService`：`getAccessToken / getUserIdentity / getUserDetail`（self 分支 gettoken 走代理；suite 分支经 corpAccessToken 已覆盖）
   - `SessionArchiveService`：构造 config 数组加 proxy 键，gettoken + msgaudit/get_chat_data
5. **服务商接口 6 处永不走代理**(保持主服务器 IP，服务商白名单，fail-fast)：`get_suite_token ×2`（suiteAccessToken/testSuiteToken）/ `get_provider_token` / `get_pre_auth_code` / `get_customized_auth_url` / `get_permanent_code`
6. **约束**:代理故障 fail-fast(明确错误日志,不回退直连,防 60020 + IP 泄露);token 缓存不受影响(企微每次请求实时校验出口 IP,不绑 token)
7. **上层传递**:Community 模块（WeWorkCommunityDriver/WeWorkBotDriver）、Ibot WechatWorkChannel、KF/App Driver 在构造 WechatWorkApiClient 时从租户上下文读代理传入（9.4 随驱动双轨一并落地）

## 9.2 互斥防御下沉(防 AI 工具绕过)

- `SocialiteService::updateOAuthConfig`:增加套件授权检查(wechat_work + corp_id 非空且该租户已有套件授权 → 422),使 `SaveOAuthConfigTool` 等直调路径与 `TenantOAuthController` 行为一致

## 9.3 ibot 双轨化(✅ 2026-08-28 完成)

1. **出向**:`WechatWorkChannel::apiClient` 检测租户套件授权 → `new WechatWorkApiClient(corp_id, '', agent_id, tokenResolver: fn() => corpAccessToken(tenantId))`;无授权走原自建
2. **入向**:`SuiteCallbackController::handleAppEventByCorpId` 对 `MsgType=text` 消息事件按 corp_id → tenant → ibot 记录(`channel_type=wechat_work`)转发 `IbotGateway::handleInbound`(代开发应用消息回调走模板统一回调,ibot 原按 ibotId 路由的 URL 收不到)
3. **配置**:`SaveIbotConfigTool / IbotSetupStatusTool / IbotAdminController` 的 corp_secret 校验改为「自填或套件授权回退」;前端引导文案双轨

实施记录(2026-08-28):新建 `WechatWorkSuiteGuard`(class_exists + Schema::hasTable 跨包守卫,供 WechatWorkChannel 与三个配置工具复用);出向 apiClient 双轨 + 注入 WechatWorkProxy::resolve 代理;入向 forwardToIbot(catch \Throwable 仅记日志不阻塞企微 5 秒响应);配置工具 suite 授权时 requiredFields 排除 corp_secret + 返回 mode 字段(suite/self)+ 文案区分。测试:WechatWorkChannelTest +4、WechatWorkSuiteCallbackTest +2、IbotAssistantToolsTest +2,首跑暴露 1 个真实缺陷:**回调为公开端点(无租户上下文),forwardToIbot 中 Ibot 查询受 TenantScope fail-closed 影响(WHERE 1=0)静默返回 null → 消息不转发** → 修复与 authorizationByCorpId 同模式:`TenantScope::allowUnscoped` 包裹显式 tenant_id 查询。验证:相关 4 文件 61 用例全过。

## 9.4 其余驱动统一(✅ 2026-08-28 完成)

1. **WeWorkBotDriver**:构造支持 tokenResolver 双轨(现只收 corpId/corpSecret)
2. **EnterpriseWechatAppDriver / KfDriver**:凭证来源支持套件授权(代开发租户消息渠道)
   - KF 双轨形态(2026-08-28 查证):自建 = 企业后台微信客服-API 的 kf_secret;代开发 = 模板权限「微信客服」+ 企业将微信客服**授权给服务商托管**(同时仅一家,可取消)→ 用企业 token(permanent_code 换)调 kf 接口,无需独立 kf_secret
3. **getOAuthConfigForDisplay**:suite 租户显示真实回调地址(现读自建值,显示空/错)
4. **CommunityChannelManager**:channels 表优先分支加互斥(套件授权租户禁写 self 凭证)

实施记录(2026-08-28):框架侧 ChannelManager::withSuiteCredentials 注入(mode=suite + tenant_id + corp_id/agent_id 回填 + proxy)+ 双驱动 suite 构造 + SocialiteService 显示回退;scrm 侧 WeWorkBotDriver/GroupBotDispatcher/CommunityChannelManager 读侧互斥 + ChannelController 写侧 422。测试新建 `tests/WechatWorkChannelDualTest.php`(8 用例)并修复 2 个测试暴露的真实缺陷:① WechatWorkSuiteService::authorization 补 TenantScope::allowUnscoped(webhook 无租户上下文时 suite 注入静默失效);② EnterpriseWechatKfDriver readonly 属性双赋值报错 → 改局部变量。验证:相关 8 文件 131 用例全过。

## 9.5 测试计划(✅ 2026-08-28 完成,按 testing.md 只跑受影响文件)

- `tests/WechatWorkProxyTest.php`(新建):resolveProxy 解析、注入后 Http 请求带 proxy 头、服务商接口不带
- `tests/WechatWorkSuiteServiceTest.php`:corpAccessToken 走代理场景
- `tests/IbotChannelTest.php`(若存在)/ WechatWorkChannel 相关:双轨凭证构造
- `tests/WechatWorkSuiteCallbackTest.php`:应用 text 消息事件转发 ibot
- `tests/SocialiteServiceTest.php`:updateOAuthConfig 套件授权 422

## 9.6 模块边界重构:OAuth 只留开关,企微配置全进 WechatWork 模块(2026-08-28 决策)

**原则**:企微接入(凭证/token/回调/权限/IP 代理)是底层能力,OAuth 登录只是消费方之一;WechatWork 模块成为企微一切的唯一承载者。

1. `WechatWorkOAuthService` 从 Auth 模块**迁入 WechatWork 模块**(登录服务本体),Auth 侧 `SocialiteService` / `TenantOAuthController` 改为委托调用(薄壳,不承载企微逻辑)
2. tenant_settings:`oauth.wechat_work_enabled`(bool 开关)保留在 oauth 组,其余企微配置(凭证/回调/代理/能力)统一收入 `wechatwork.*` 组
3. 微信(公众号)同构:未来配置收 WechatOfficial 模块,Auth 只留开关(权限、配置复杂度同理)
4. 迁移兼容:旧 `oauth.wechat_work.*` 存量配置读时迁移(读新写旧,或一次性迁移命令)
5. 权衡不变式:一个租户一个企微——模式判定仍以套件授权状态为唯一判据,自建配置为回退轨

---

# 十、能力 × 权限 × 付费等级表(2026-08-28)

> 依据:2026-08-23 能力盘点(scrm-platform/docs/2026-08-23-wecom-open-capability-inventory.md)+ 代码实际能力 + 企微官方计费规则(服务商许可 38914 文档,生产实测)。
> 用途:产品计费决策 —— 哪些能力套餐内含、哪些独立付费、双体系成本差异。

## 10.1 能力矩阵(2026-08-28 重构:企微许可只分两种,能力包自由组合)

> 企微服务商许可账号只有两种(官方 38914):**基础账号**(身份验证/发消息)与**互通账号**(客户联系/群);SCRM 场景成本模型即这两个维度。

| 能力 | 企微侧权限 | 企微许可类型 | 代开发 | 自建 | 平台计费 |
|---|---|---|---|---|---|
| 企微扫码登录 | 应用可见范围(身份验证)| 基础账号 | ✅ | ✅ | 套餐默认(免费)| 
| 应用消息推送(message/send)| 应用消息 | 基础账号 | ✅ | ✅ | **基础包** |
| AI 数字员工 ibot | 应用消息 + 回调 | 基础账号 | ✅(需 9.3)| ✅ | **基础包**(AI 用量另计)| 
| 内部群机器人(appchat)| 应用消息(内部群)| 基础账号 | ✅ | ✅ | **基础包** |
| 通讯录同步(user/department)| 通讯录只读 | 互通账号 | ✅ | ✅ | **互通包** |
| 客户档案同步(externalcontact)| 客户联系 | 互通账号 | ✅ | ✅ | **互通包** |
| 客户群同步(群列表/成员)| 客户联系·客户群 | 互通账号 | ✅ | ✅ | **互通包** |
| 客户群发(add_msg_template,员工确认流)| 客户联系·企业群发 | 互通账号 | ✅ | ✅ | **互通包** |
| 欢迎语(send_welcome_msg)| 客户联系·欢迎语 | 互通账号 | ✅ | ✅ | **互通包** |
| 微信客服(kf/sync_msg)| 微信客服(企业微信接管版)| 独立能力(不占许可账号,待实测确认)| ✅(服务商托管授权,同时仅一家)| ✅(应用授权)| **互通包** |
| 会话存档(msgaudit)| 会话存档(**企微官方付费**)| 官方付费能力 | ❌ 平台不可用(仅企微官方客户端展示组件,不可入库)| ✅ 完整(明文入库)| **增值加购(仅自建)** |
| 自建模式(独立出口 IP + 自有域名)| 企业可信 IP = 代理 IP | - | - | ✅ | **增值加购(200-300 元/年/客户)** |
| 代开发许可账号 | 服务商许可(平台垫付)| - | ✅ | - | **按人转嫁(90 天免费期后)** |

## 10.2 付费细则

| 计费项 | 单价(元/年) | 说明 |
|---|---|---|
| 代开发许可·基础账号 | 5(1-5 人档,阶梯累进)| 身份验证/发消息;90 天免费试用;按实际使用人数非企业总人数 |
| 代开发许可·互通账号 | 50(1-5 人档,阶梯累进)| 客户联系/客户群;同上 |
| 自建出口 IP | 200-300/客户 | 平台统一采购代理,按客户分配独立出口 IP |
| 会话存档 | 企微官方定价,按人 | 转嫁客户(平台代开通或客户自理)|
| 服务商认证 | 300/平台 | 平台成本,不分摊到单租户 |

许可阶梯(按企业累计账号数):基础 5/4/3/2/1 元,互通 50/40/30/20/10 元(1-5 / 6-200 / 201-500 / 501-1000 / 1001-10000 人档)。

## 10.3 能力边界(不可承诺项,防过度销售)

| 边界 | 说明 | 现行处理 |
|---|---|---|
| 群接龙 | 无 API | 模板引导 + 表单收集 |
| 群禁言 | 无 API(muteMember 降级)| 不承诺 |
| 客户群公告 | 无开放接口 | 降级群消息 |
| 踢人 | 仅自建群(appchat/update)| 客户群走企微后台 |
| 主动拉人入群 | 无 API | 活码/邀请链接 |
| 客户群发频率 | 官方 1 次/天/客户 + 员工确认 | 产品设计而非缺陷 |

## 10.4 决策记录(原「待确认项」,2026-08-28 全部决议)

| 原待确认项 | 决议 | 落点 |
|---|---|---|
| 代开发许可费转嫁方式 | 配额内含,超量按人计量(阶梯转嫁);90 天免费窗口平台成本台账 | 11.3 |
| 自建出口 IP 计费形态 | 套餐含 1 个(limits.wechat_work_proxy_ips),加购独立订单(200-300/年)| 11.1/11.3 |
| ibot AI 用量计费 | 走现有 AiUsageService monthly 聚合,不另立 | 11.3 |
| 会话存档代开发展示模式 | 平台不提供;仅企微官方客户端展示组件(不可入库),存档需求客户引导自建 | 10.6/10.7 |

## 10.5 成本拐点:代开发 vs 自建决策模型(2026-08-28)

**决策树**:
1. 租户**无自有备案域名**(主体校验过不了)→ **只能代开发**(扫码授权,零企业侧成本)
2. 有域名 → 按成本拐点选:

| 场景 | 代开发成本(平台,元/年)| 自建成本(平台,元/年)| 拐点 |
|---|---|---|---|
| 纯消息(基础账号 5 元/人)| 5N | ~200-300(IP)+ 域名(0-100)| N≈40-60 人 |
| 含客户联系/客户群(互通账号 50 元/人)| 50N | ~200-300(IP)+ 域名(0-100)| **N≈4-6 人** |
| 混合(基础 B + 互通 G)| 5B+50G | ~200-300(IP)+ 域名 | 5B+50G≈200-300 |

**结论(2026-08-28 修正)**:代开发**按人计费**(人越多越贵),自建是**固定成本**(人越多摊得越薄):
- 互通人数 **<4-5 人 → 代开发更便宜**(许可费低)
- 互通人数 **>4-5 人 → 自建更便宜**(IP 固定成本摊薄,人越多越划算)
- 纯消息场景拐点在 40-60 人(基础账号 5 元/人太便宜,小团队代开发几乎无成本)

**辅助决策因素**:
- **90 天免费试用**:代开发新授权 90 天内许可零成本 → 试用/短期客户一律代开发;长期客户按拐点决策(人数会涨 → 自建)
- 会话存档需入库分析(质检/AI)→ **只能自建**(代开发仅展示)→ 有存档需求的客户即使人数少也优先自建
- 客户对 IP/域名合规敏感(金融等)→ 自建(企业可信 IP = 自有代理 IP)
- 客户希望零配置启动 → 代开发(扫码即用)

## 10.6 能力边界:仅自建可用(2026-08-28 官方文档查证)

| 能力 | 自建 | 代开发 | 依据 |
|---|---|---|---|
| 会话存档(msgaudit 明文入库)| ✅ | ⚠️ 仅展示组件,不可拉取明文 | path/99661:三方接口「不含内容」+ 展示组件;需服务商单独申请「会话存档接口授权」+ 企业单独授权(版本+成员范围)|
| 会话内容导出 | ❌ 暂不支持 | ❌ 暂不支持 | path/99620:自建/代开发/第三方应用均暂不支持(需「会话存档接口授权」)| 
| 应用信息配置(set_agent)| ✅ 可调 | ❌ 不可调 | path/90228:仅企业可调用(配置类,非业务能力)|

**结论**:会话存档是唯一「自建专属」业务能力;其余能力(登录/通讯录/客户/客户群/群发/欢迎语/客服/应用消息/ibot)双轨均支持。

## 10.7 平台套餐:两层自由组合(2026-08-28)

**用户视角**:套餐 = 接入方式(权限天花板)× 能力包(企微许可成本)× 增值项

| 维度 | 选项 | 说明 |
|---|---|---|
| **接入方式** | 代开发(默认)| 零门槛/扫码即用/按人计费/能力受限(会话存档仅展示)| 
| | 自建(自由)| 固定成本 200-300/年/客户/出口 IP 独享/能力完整(存档明文入库)/需自有域名 → 面向规模化与合规客户 |
| **能力包** | 基础包(纯消息)| 登录 + 应用消息 + ibot + 内部群(基础账号 5 元/人/年)| 
| | 互通包(带客户)| 客户档案/客户群/群发/欢迎语/客服(互通账号 50 元/人/年)| 
| **增值项** | 会话存档 | 仅自建可用,官方费转嫁 + 平台服务费 |
| | AI 用量 | 现有 AiUsageService monthly 聚合 |
| | 独立出口 IP | 自建必含,200-300 元/年 |

**套餐自由组合原则**:
- 不做死板 L0-L3 固定分级;基础包/互通包自由选(互通包依赖基础包,阶梯许可按人累进)
- 接入方式可切换:人数过拐点(互通 4-5 人/纯消息 40-60 人)引导切自建;90 天免费窗口内切换零成本
- 企微侧许可只有两种 → 平台成本模型只有两个维度,转嫁策略简单(按人 / 按客户固定)
- 会话存档绑定自建(能力边界)→ 需要存档质检的客户自然升级自建,与规模化客户路径重合

## 10.8 微信客服专项(2026-08-28,用户强调不可漏项)

**双版本(数据不互通,两套接口)**:
- **独立版**(kf.weixin.qq.com):独立后台,API 对接无域名主体校验 → 不经过我们企微体系
- **企业微信接管版**(work.weixin.qq.com → 应用管理 → 微信客服):我们接入的版本(EnterpriseWechatKfDriver),有主体校验

**双体系接入形态**:

| 维度 | 自建 | 代开发 |
|---|---|---|
| 开通 | 企业后台应用管理-微信客服,设置可调用接口的应用(可信 IP + 域名主体校验)| 服务商模板权限勾选「微信客服」→ 企业把微信客服授权给服务商托管(同时仅一家,可取消)| 
| 凭证 | 微信客服-API 的 kf_secret(独立于应用 secret)| 企业 token(permanent_code 换),无需 kf_secret |
| 回调 | 企业侧配置 | 服务商托管后由服务商统一处理(待实测确认)| 
| 能力 | 账号管理/会话分配/收发消息/带参客服链接 | 同左(服务商可托管)| 

**计费定位**:微信客服是**独立能力,不占基础/互通许可账号**(客户主动发起,非员工加好友沟通),企业侧开通免费;平台侧归入**互通包**(客户触达价值层)。待实测确认:代开发调 kf 接口是否触发任何许可校验。

**代码缺口**(9.4-C2):KfDriver 现只支持自建 kf_secret → 代开发租户需 tokenResolver 双轨(复用 corpAccessToken)。

---

# 十一、企微能力 × 套餐体系落地设计(2026-08-28)

> 原则:复用现有 Billing/tenancy 体系,零新表——`SubscriptionPlan` 已原生支持 features(hasFeature)/ limits(getLimit)/ metered_price / overage_price,套餐差异化开通有 plan_modules 先例,租户级配置有 tenant_settings 先例。

## 11.1 数据模型(零迁移)

1. **能力包 → `subscription_plans.features`(数组,复用 hasFeature)**:
   - `wechat_work_base`:基础包(登录 + 应用消息 + ibot + 内部群)—— free 起含
   - `wechat_work_intercom`:互通包(客户/客户群/群发/欢迎语/客服)—— 推荐 basic 起含
   - `wechat_work_self`:自建模式(出口 IP 独享 + 完整权限,会话存档明文)—— 推荐 pro/enterprise
   - `wechat_work_archive`:会话存档增值(仅自建可用)—— 独立加购
2. **配额 → `subscription_plans.limits`(数组,复用 getLimit)+ metered**:
   - `wechat_work_license_basic`:基础许可账号数(默认按套餐用户数比例)
   - `wechat_work_license_intercom`:互通许可账号数
   - `wechat_work_proxy_ips`:出口 IP 数(自建默认 1)
   - metered_price:许可账号超量按人计价(复用现有 metered 机制,阶梯价企微侧定,平台加价转嫁)
3. **租户实际用量 → `tenant_settings` 组 `wechatwork.usage`**(JSON,无迁移):
   - `license_basic_used` / `license_intercom_used`(已激活许可账号数)
   - `proxy_ip`(分配的代理出口 IP,展示用)
4. **套餐覆盖**:能力差异走 features(能力级),不新增 plan_modules 条目(模块级开关,企微模块本身常驻开通)

## 11.2 权限开通链路(能力 gate)

- 新增 `WechatWorkCapability` 服务(WechatWork 模块内):`has(tenantId, 'base'|'intercom'|'self'|'archive')` → 读 tenants.subscription_plan_id → SubscriptionPlan->hasFeature
- **调用点**(全部企微能力入口):
  - `WechatWorkOAuthService::getConfig`(base,登录)
  - `CommunityChannelManager::credentials`(intercom,客户/客户群/群发/欢迎语)
  - `EnterpriseWechatKfDriver`(intercom,客服)
  - `WechatWorkChannel::apiClient`(base,ibot)
  - `SessionArchiveService`(archive,会话存档)
- 能力不足 → 明确错误(feature_not_enabled 风格,对齐会话存档先例),不静默
- **接入方式不设 gate**:代开发 = 默认接入方式(free 可用);自建 = features.wechat_work_self 门控(付费能力)

## 11.3 计费规则

| 计费项 | 承载 | 规则 |
|---|---|---|
| 许可账号(代开发)| limits + metered_price | 配额内含;超量按人计量(阶梯转嫁);90 天免费窗口(授权记录 authorized_at + 90 天,平台成本台账,复用 Billing 计量)| 
| 出口 IP(自建)| limits.wechat_work_proxy_ips | 套餐含 1 个;加购独立订单(200-300/年)| 
| 会话存档 | 独立订单/计量 | 官方费转嫁 + 平台服务费(仅自建)| 
| AI 用量 | 现有 AiUsageService | 不变 |

## 11.4 admin 端(平台运营)—— 对齐现有三入口,不新建散落页

**现状盘点(2026-08-28 学习)**:admin 端已存在三个相关入口,本设计在其上扩展而非新建:

1. **企微服务商管理(已存在,零开发)**:`AdminServiceProviderController`(服务商 CRUD + 授权列表 authorizations + `appCallbackUpdate` 模板回调 Token/EncodingAESKey 配置)—— console 端提示「管理后台 → 企微服务商」的入口已有,代开发链路平台侧配置无需新增
2. **套餐能力定义(Billing Plans.vue,需扩展)**:`admin/ui/{bootstrap,element-plus}/views/Plans.vue` 已支持 features 编辑(逗号分隔),**缺 limits / metered_price 表单**;后端 `SubscriptionController::storePlan/updatePlan` 校验也缺 `metered_price / metered_unit / overage_allowed / overage_price / rate_limit_rpm` 字段(模型已支持,仅校验没放行)→ 需双套 UI 表单 + 后端校验补全
3. **租户级「企微接入」区块(租户管理页,需新建)**:对齐现有企微 admin 路由口径(`rbac.permission:setting.view/update`,Routes/admin.php 先例)+ 操作补 AuditService::log 审计(Billing 模块 ensureSuperAdmin 是自身先例,企微模块延续 rbac 口径):
   - 能力包状态:当前套餐 features 展示(base/intercom/self/archive 勾选态)+ 套餐切换入口(复用 SubscriptionService::changePlan)
   - 出口代理配置：wechatwork.proxy 组（enabled/host/port/username/password/exit_ip）+ 出口 IP 展示（客户要加到企业可信 IP）
   - 许可账号台账:已购/已激活/超量告警(读 wechatwork.usage + limits)
   - 模式强制切换:运营代客户切代开发/自建(触发 9.6 切换流程,revoke/重配引导)
   - 审计:每项操作 AuditService::log(对齐 admin_change_plan 先例)

## 11.5 console 端(租户自服务)—— OAuthSettings.vue 增量扩展

**现状盘点(2026-08-28 学习)**:`Auth/resources/console/ui/{bootstrap,element-plus}/views/OAuthSettings.vue` **已实现套件授权 UI 约 90%**(二维码授权/授权状态/模板权限标签/回调链路状态/解除授权 + 自建表单互斥提示 444-446 行),本设计只做增量:

1. **能力包状态**:新增卡片展示当前套餐 features(base/intercom/self/archive 含/不含),缺失项引导升级(链接套餐页,复用 subscription/plans 接口)
2. **许可账号用量**:已激活/配额(wechatwork.usage + limits),超量提示升级;90 天免费窗口倒计时(代开发授权后)
3. **出口 IP 展示**:自建租户显示分配的代理 IP + 「加入企业可信 IP」操作指引(静态文案,可读)
4. 双套 UI 同步(bootstrap + element-plus,对齐现有 OAuthSettings 双套结构)

## 11.6 实施顺序与测试

1. **后端补全**:`SubscriptionController::storePlan/updatePlan` 校验放行 metered_price/metered_unit/overage_allowed/overage_price/rate_limit_rpm + SubscriptionPlan 种子数据(每套餐 features/limits/metered_price 定义)
2. `WechatWorkCapability` 服务 + 5 处调用点接入(11.2)
3. **admin Plans.vue 双套扩展**(limits/metered_price 表单)+ 租户管理页「企微接入」区块
4. **console OAuthSettings 增量**(能力包状态/许可用量/出口 IP,双套)
5. 测试:`tests/WechatWorkCapabilityTest.php`(features/limits 解析、能力不足错误、90 天窗口计算);Billing 回归(SubscriptionController 校验);受影响回归(WechatWorkOAuthService/Community 相关)

---

# 十二、总实施顺序与里程碑(2026-08-28 review 整理)

> 一~八章已落地;以下为九、十一章待实施项的统一执行顺序(依赖关系唯一,不可跳序)。
> **顺序调整说明**:原确认顺序为 9.1→9.2→9.3→9.4→9.6;review 后改为 **9.6 提前到最先**——WechatWorkOAuthService 迁移是 9.1 代理改造(改该服务 3 处 Http 调用点)与 9.4 驱动改造的前置,先迁移后改造可避免同一文件改两遍。

## 12.1 阶段 A:隔离地基(9.6 → 9.1 → 9.2 → 9.4)

1. **9.6 模块边界重构**(先做,后续所有改造的落点前提):
   - WechatWorkOAuthService 迁入 WechatWork 模块;Auth 侧 SocialiteService/TenantOAuthController 变薄壳委托
   - `oauth.wechat_work_enabled` 开关留 oauth 组,其余配置收 `wechatwork.*` 组;存量配置读时迁移
   - 验证:Auth 侧薄壳 + WechatWork 侧登录测试回归
2. **9.1 出口代理**:
   - `WechatWorkProxy::resolve` helper + tenant_settings `wechatwork.proxy` 组 + admin 配置 API（9.1-2，UI 归 11.4）
   - WechatWorkApiClient 构造加 `?string $proxy` + 企业接口 21 处统一 http() 注入 + 服务商接口 6 处隔离 + fail-fast
   - 验证：`tests/WechatWorkProxyTest.php` 新建
3. **9.2 互斥防御**:SocialiteService::updateOAuthConfig 套件授权 422 → `tests/SocialiteServiceTest.php`
4. **9.4 其余驱动**:WeWorkBotDriver / EnterpriseWechatAppDriver / KfDriver tokenResolver 双轨 + getOAuthConfigForDisplay + CommunityChannelManager 互斥 → 各驱动相关测试

## 12.2 阶段 B:ibot 双轨(9.3)

1. 出向:WechatWorkChannel::apiClient tokenResolver(检测套件授权)
2. 入向:SuiteCallbackController::handleAppEventByCorpId 对 MsgType=text 转发 IbotGateway::handleInbound
3. 配置:SaveIbotConfigTool / IbotSetupStatusTool / IbotAdminController corp_secret 回退套件授权 + 文案双轨
4. 验证:tests/WechatWorkSuiteCallbackTest 补充(text 转发)+ Ibot 相关回归

## 12.3 阶段 C:套餐体系(11)

1. Billing 后端校验补全(metered_price/metered_unit/overage_allowed/overage_price/rate_limit_rpm 放行)+ SubscriptionPlan 种子数据(features/limits/metered_price)
2. WechatWorkCapability 服务 + 5 处调用点(11.2)
3. admin Plans.vue 双套扩展(limits/metered_price 表单)+ 租户管理页「企微接入」区块
4. console OAuthSettings 增量(能力包状态/许可用量/出口 IP,双套)
5. 验证:tests/WechatWorkCapabilityTest.php + Billing 回归

## 12.4 里程碑与部署

| 里程碑 | 内容 | 完成标准 |
|---|---|---|
| M1 隔离地基 | 阶段 A 完成 | 代开发/自建租户出口 IP 完全隔离;互斥不可绕过;驱动双轨 |
| M2 双轨完整 | 阶段 A+B 完成 | 代开发租户全能力可用(含 ibot 收发);无纯自建假设残留 |
| M3 商业化 | 阶段 C 完成 | 能力门控生效,套餐可售卖,两端 UI 可用 |

每里程碑后按 testing.md 只跑受影响文件;部署走 deploy.md 唯一链路(框架 push → split.yml → scrm composer update + lock commit → deploy.py incremental)。
