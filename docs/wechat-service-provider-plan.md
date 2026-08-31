# 微信第三方平台（服务商模式）双轨实施记录

## 背景与目标

企微已实现「服务商（代开发）+ 自建应用」双轨登录（见 `wecom-service-provider-plan.md`）。微信登录此前只有自建模式（`Auth` 模块 `WechatOAuthService`，开放平台网站应用扫码）。本期新建 **`src/Modules/Wechat`** 模块，以微信第三方平台补齐服务商模式，与企微双轨架构对齐。

**已确认决策**：
- 模块承载：新建 `src/Modules/Wechat`（PascalCase），拆包 `dsplat/multi-tenant-saas-module-wechat`
- 授权范围：`auth_type=3`（公众号+小程序），一租户一授权（`tenant_id` UNIQUE），`authorizer_type` 区分账号类型；首期启用公众号 H5 网页授权登录，小程序授权记录仅存储为后续 `jscode2session` 铺路

## 概念映射（企微 → 微信）

| 企微代开发 | 微信第三方平台 | 实现载体 |
|---|---|---|
| suite_ticket（10min 推送） | component_verify_ticket（10min 推送、12h 有效） | `WechatComponentService::storeComponentVerifyTicket` |
| suite_access_token / provider_access_token | component_access_token（2h） | `componentAccessToken()`（Cache 提前 300s 过期） |
| get_customized_auth_url 授权二维码 | componentloginpage（PC）/ bindcomponent（H5）授权链接 | `buildAuthorizeUrl()`（pre_auth_code，1800s） |
| permanent_code | authorizer_refresh_token（不解除授权即永久有效） | `exchangeAuthorization()`（api_query_auth）+ `refreshAuthorizerToken()` |
| corpAccessToken（gettoken） | authorizer_access_token（api_authorizer_token） | `refreshAuthorizerToken()` |
| create_auth / cancel_auth 事件 | authorized / updateauthorized / unauthorized 事件 | `ComponentCallbackController` 分发 |
| WechatWorkCrypto（AES-256-CBC + PKCS7） | 同构协议（random16+len4+msg+receiveid） | `src/Support/Wechat/WechatCrypto.php`（独立实现，模块拆包不依赖 WechatWork） |

**官方文档验证的关键事实**：
- 公众号授权给第三方平台后，**网页授权由第三方平台代替实现，公众号无需配置网页授权域名** —— 登录可行性的官方依据
- 授权发起页域名、授权回调域名均为第三方平台级配置（**平台域**），与企微授权回跳租户域不同
- component_verify_ticket 丢失后需在平台后台重置推送；服务商不能主动解除授权

## 实施状态（2026-09-01 全部落地）

| 阶段 | 状态 | 说明 |
|---|---|---|
| 一、模块骨架+数据层 | ✅ | 迁移（`wechat_component_providers` / `wechat_authorizations`）、模型（显式 `$table` + 加密 mutator）、`WechatServiceProvider`（裸回调路由）、`src/Support/Wechat/WechatCrypto` + `WechatApiClient` |
| 二、核心服务+回调 | ✅ | `WechatComponentService`（ticket/token/pre_auth_code/换码/state 防重放/三态探测）、`ComponentCallbackController`（GET 验签 + POST 事件分发 + authorize-callback 回跳）、`ProcessAuthorizationJob`（tries=1） |
| 三、租户授权+平台管理 API | ✅ | `TenantWechatAuthController`（status/authorize/revoke/capability）、`AdminComponentProviderController`（CRUD/test/授权列表）、三组路由 |
| 四、登录双轨 | ✅ | `WechatOAuthService` 从 Auth 迁入并扩展（component/self 双轨判定、sns/oauth2/component/access_token 换码）；`SocialiteService` wechat 分支 3 处委托 + 互斥防御 |
| 五、前端 | ✅ | admin `routes.ts` + `ComponentProviderSettings.vue`、console `WechatAuthSettings.vue`（视图自动发现） |
| 六、测试+收尾 | ✅ | 6 个新测试 + `SocialiteServiceTest` 更新（共 70 个新断言，248 断言回归全绿）；`scrm-platform/composer.json` 注册；本文档 |

## 关键差异与坑（实施中处理）

1. **登录形态差异**：component 模式 = 公众号粉丝 **H5 网页授权**（snsapi_userinfo，需在微信内打开），非 PC 扫码；PC 扫码登录仍走 self 模式（开放平台网站应用）。双轨按租户有无授权自动切换，两者并存互补
2. **授权回调必须平台域**：第三方平台「授权回调域名」为平台级配置，授权完成跳 `https://auth.neihang.com/api/v1/wechat/authorize/callback`（裸路由），与企微租户域回跳不同；state 编码租户 ID 用于恢复租户上下文
3. **服务商不能主动解除授权**：revoke 仅本地标记 + 引导公众号管理员在公众平台「第三方平台-我的授权」取消；unauthorized 事件（含 AuthorizerAppid）到达后确认
4. **IP 白名单（61004）**：component_access_token 获取需第三方平台 IP 白名单；测试期最多 10 个授权测试公众号（填原始 ID）
5. **61003**：未授权关系调用 component 接口（如无授权记录时刷新 token），作为三态探测的「已解除」判定
6. **component_verify_ticket 12h 有效**：10 分钟推送，丢失需平台后台重置推送（初始化时提示平台侧配置）
7. **authorized 事件不带 auth_code**（与企微 create_auth 不同）：仅记日志，入库主路径是浏览器回跳 authorize-callback（auth_code 一次性、600s 有效）
8. **admin 平台级查询需豁免 TenantScope**：platform Operator 请求无租户上下文，`Authorization` 查询 fail-closed（WHERE 1=0）导致授权列表空/删除保护失效，须 `TenantScope::allowUnscoped`（测试发现并修复）

## 验收标准（手动清单，需微信平台侧配合）

- [ ] 平台后台配置组件凭证后，回调 URL 连通性测试通过（GET 验签）
- [ ] 租户 console 发起授权 → 授权页展示公众号+小程序 → 勾选公众号授权 → 回跳 console 状态 authorized
- [ ] 授权后租户 H5 微信内打开公众号网页授权 → 免密登录成功（component 模式）
- [ ] 未授权租户 PC 扫码登录仍走 self 模式（无回归）
- [ ] 公众号管理员取消授权 → unauthorized 事件 → 本地标记 revoked，H5 登录回退 self

## 假设

- 回调平台域沿用企微统一回调域（auth.neihang.com 一类），由现有域名解析机制提供
- 首期 component 登录仅面向公众号 H5 网页授权；小程序授权记录仅存储，jscode2session 登录为后续独立任务
- 一租户一授权记录（tenant_id UNIQUE）；需同时使用公众号+小程序时先解除再重新授权（本计划不引入多授权记录管理）
