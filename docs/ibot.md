# AI 消息小助理（ibot）设计规范

> 状态：**Phase 0/1 已上线生产**（Telegram + 企业微信双频道，蓝眼兔租户实测通过，L2 IM 内文本确认已实施）；
> Phase 2/3（Connector / 微信 iLink / 飞书 / 钉钉 / 微信客服）未启动
> 关联：`docs/task-chain.md` · `docs/event-plan.md` · AI 小助手完整化计划
> 实现位置：`src/Modules/Ibot/` · 共享 SDK `src/Support/WechatWork/` · 渲染适配 `src/Support/Messaging/MarkdownAdapter.php`
> 核心思想：**ibot 是各 IM 平台上的机器人实体，operator 扫码添加后，随身携带自己的 AI 小助理**

## 一、定位与边界

ibot 把 AI 小助手从 Web 控制台延伸到 operator 的日常 IM 里：
在微信/企业微信/钉钉/飞书/Telegram 上注册**消息机器人**，operator 扫机器人的二维码加为联系人，
之后直接在 IM 里与自己的数字员工对话——查数据、下指令、收通知，不必打开控制台。

三个关键约束：

- **ibot 是机器人实体，不是公众号内容通道，也不是 OAuth 登录**：扫的是机器人的添加码，扫完即进 bot 会话
- **ibot 不是频道会话/聊天消息系统**：它是 operator 的个人 AI 通信助理（参照 OpenClaw 个人 AI 网关），
  消息只进 AI 会话（agent_conversations），**不进** Conversation/Channel 客服会话体系；
  两者仅在“都连 IM 平台”上形似，职责完全不同，互不依赖
- **每个 operator 每个频道绑定一个 ibot**，并可在已绑定频道中**设定一个默认消息通道**（系统通知的出口）
- **服务对象是 Operator**（身份模型铁律）：ibot 面向后台运营人员；面向 User 的客服机器人是另一件事（scrm ChannelAiService 已覆盖），不在本设计范围

设计原则（沿用既定铁律）：

- **AI 可选性**：ibot 网关故障只影响 IM 侧交互，Web 控制台助手、业务模块完全不受影响
- **执行永远经既定链路**：IM 消息进来走同一个 AgentRuntime（编排）+ AgentToolExecutor（工具执行）+ ToolRegistry，不另建 AI 调用路径
- **写操作必确认**：L2 工具在 IM 内走文本确认（见第六节），不绕过风险分级

## 二、各平台机器人形态与传输协议（对齐 OpenClaw 研究）

ibot 的参照形态是 OpenClaw 个人 AI 网关：channel 插件 + 常驻 Gateway + 背后的 agent。
关键认知：**传输协议不是单一 webhook，而是三种形态**，统一收敛到同一个 `IbotChannelContract` 之后：

| 传输形态 | 方向 | 原理 | 适用平台 |
|---|---|---|---|
| **长连接/长轮询** | 出站 | 我方主动建连收事件，**无需公网回调 URL** | 飞书 WS（默认）/ 钉钉 Stream（**唯一可用模式**）/ Telegram long polling（默认） |
| **webhook 回调** | 入站 | 平台 POST 到我方公网 URL，需公网可达 + 验签/加解密 | 企业微信（**唯一选择**）/ 微信客服 / Telegram（可选）/ 飞书（可选） |
| **私有 API sidecar** | 出站 | 厂商专用 API，扫码登录取凭证，常驻进程收发 | 微信个人号（**腾讯 iLink API**，即 `@tencent-weixin/openclaw-weixin` 插件同源协议） |

| 平台 | 传输 | 需公网？ | 机器人形态 | 添加/登录方式 | 主动推送 |
|---|---|:---:|---|---|---|
| **Telegram** | long polling（默认）/ webhook | 否 | Bot（BotFather 创建） | `t.me/<bot>?start=<绑定码>` 链接做成二维码 | 无限制 |
| **飞书** | WebSocket（默认）/ webhook | 否 | 自建应用机器人 | 扫码/搜索加 bot | 单聊无限制 |
| **钉钉** | Stream（WS，**唯一可用**，HTTP 推送不工作） | 否 | 企业内部机器人 | 扫码进单聊 / 组织内搜索 | 单聊无限制 |
| **微信（个人号）** | iLink API sidecar | 否 | 个人微信账号机器人 | 手机微信扫**登录二维码**取账户凭证；operator 加该号为好友 | 好友消息无限制 |
| **企业微信** | webhook（**唯一选择**） | **是** | 自建应用（应用机器人） | 扫应用二维码 | 应用消息无限制 |
| **微信（客服）** | webhook | 是 | 微信客服机器人 | 扫客服码进机器人会话 | 会话态内可推 |
| **QQ（预留）** | 待确认（社区插件 openclaw-china） | — | — | — | — |

各平台关键配置门槛（实施时的坑，提前登记）：

- **Telegram**：每个 Bot Token 同时只允许**一个活跃轮询器**（多实例部署需选主）；
  轮询需看门狗机制（连续 120s 无 getUpdates 活性则自动重启）
- **钉钉**：必须 Stream 模式；需 5 个凭证（Client ID/AppKey、Client Secret、Robot Code、Corp ID、Agent ID）
- **企业微信**：回调 URL 需公网可达（SaaS 服务端天然满足）；Token + EncodingAESKey（必须恰 43 字符）
  消息加解密；服务器公网 IP 必须加入「企业可信 IP」，否则 API 调用**静默失败**
- **飞书**：WS 模式零额外加密配置，部署门槛最低

边界说明：微信个人号通道基于**腾讯微信团队官方提供的 iLink API**（OpenClaw 生态中由腾讯自己发布插件），
是合规路径，**不采用 wechaty/逆向协议类方案**；微信客服与个人号两种形态由租户按需选用。

## 三、数据模型

### ibots 表（租户级机器人实例）

| 字段 | 类型 | 说明 |
|---|---|---|
| ibot_id | bigint PK（全局 ID） | |
| tenant_id | bigint index | 租户隔离铁律 |
| channel_type | string | telegram / wechat_work / wechat_kf / wechat（个人号 iLink）/ dingtalk / feishu |
| transport | string | webhook / longconn（WS/长轮询）/ ilink（私有 API sidecar） |
| name | string | 机器人展示名 |
| credentials | json encrypted | 按平台结构不同：TG bot token；钉钉 5 凭证（AppKey/AppSecret/Robot Code/Corp ID/Agent ID）；企微 corp secret + Token + EncodingAESKey；飞书 app id/secret；iLink 账户凭证引用 |
| webhook_secret | string | 本系统为该 bot 生成的回调验签密钥 |
| agent_id | bigint nullable | 机器人背后的数字员工人格（空 = system_secretary，与 Web 助手同人） |
| status | string | active / disabled |
| created_at / updated_at | timestamp | |

### operator_ibot_bindings 表（operator ↔ 机器人绑定）

| 字段 | 类型 | 说明 |
|---|---|---|
| binding_id | bigint PK | |
| tenant_id | bigint index | 绑定时选定的租户上下文（operator 多租户时据此定界） |
| operator_id | bigint index | |
| ibot_id | bigint FK | |
| external_id | string | 平台侧身份：chat_id（TG）/ userid（企微/钉钉）/ open_id（飞书）/ external_userid（微信客服） |
| conversation_id | bigint nullable | 承载对话的 agent_conversation（首次消息时创建并复用） |
| is_default_channel | boolean | 默认消息通道（每 operator 至多一个 true） |
| status | string | pending（码已发未扫）/ active / revoked |

唯一约束：`(operator_id, ibot_id)`、`(ibot_id, external_id)`。

**租户归属规则**：绑定动作发生在控制台（已登录某租户），binding 落该租户；
IM 消息进来时租户上下文即 binding.tenant_id，无需对话内切换。同一 operator 服务多租户时，
可对不同租户的 ibot 各建一条绑定（不同 bot 会话，天然隔离）。

## 四、扫码绑定流程

```
控制台「消息小助理」页 → 选频道 → 系统生成带绑定码的二维码（10 分钟有效）
        ↓ operator 用对应 IM 扫码
进入机器人会话，首条消息携带绑定码（TG start 参数 / 微信客服场景值 / 企微钉钉飞书组织内直接身份映射）
        ↓ IbotGateway 校验绑定码
写入 external_id ↔ operator 绑定，bot 回发欢迎语 → 绑定完成，全程无表单
```

- 绑定码一次性消费、短 TTL、绑定后立即失效
- 企微/钉钉/飞书为组织内应用时，平台身份可与通讯录直接映射，绑定码仅作 operator 归属确认

### 企微扫码即绑（2026-08，替代“扫码后手动发绑定码”)

企微官方不支持“扫码唤起应用会话并携带消息”，因此采用 **网页授权（snsapi_base）直绑** 链路：

```
控制台生成绑定码 → 二维码内容 = oauth2/authorize 授权链接（appid=corp_id, state=ibot_id:绑定码）
        ↓ operator 用企业微信扫一扫（内置浏览器静默授权）
GET /api/v1/ibot/bind/wechat-work/callback?code=&state= → user/getuserinfo 换 userid
        ↓ 渲染确认页（显示成员名 + 机器人名，pending 暂存身份，绑定码不消费）
POST /api/v1/ibot/bind/wechat-work/confirm → takePending（取走即失效）+ consume → 写入 binding
        ↓ message/send 推送「绑定成功」→ 点开消息直达应用对话框，开始对话
```

- **安全**：userid 仅来自企微 getuserinfo（非成员扫码无 userid → 拒绝）；pending 一次性取走即失效；绑定码仍一次性消费（防跨 bot/租户重放）；文本绑定码路径保留兑底（企微会话内发码同样可绑）
- **回调域（按接入模式区分，与 OAuth 登录同规则）**：
  - **代开发（suite）**：可信域名由服务商代管，只能用平台统一回调域 `auth.oauth.callback_domain`（如 auth.neihang.com）；
    租户自定义域名（如 club.lanyantu.com）仅自建模式可用，代开发模式填了必报 redirect_uri 错误
  - **自建（self）**：租户自定义域名优先（`tenants.domain`，需在企微「网页授权及JS-SDK」可信域名内），平台统一回调域兑底
- **凭证来源**：`corp_id` 取 ibot 凭证，缺失时回退租户套件授权（`wechat_work_authorizations`）；公开回调无租户上下文，查询显式 `withoutGlobalScope(TenantScope)`（与 webhook 同策略）
- 绑定成功后推送应用消息（`sendMessage`，agent_id 缺失时静默跳过）
- **微信个人号（iLink）有两次扫码，注意区分**：第一次是管理员扫**登录二维码**获取 iLink 账户凭证（一次性配对）；
  第二次是 operator 加 bot 好友后发送绑定码完成身份绑定（与其他频道同义）
- 解绑：控制台操作或对 bot 发送解绑指令（二次确认），binding 置 revoked

## 五、消息链路

### 入（operator → ibot → AI）

```
平台消息（webhook / 长连接 / iLink，经 Connector 归一）→ IbotGateway（统一入口，验签）
  → external_id 查 binding（未绑定 → 回发引导绑定话术）
  → 解析 operator + tenant + agent（ibot.agent_id ?? system_secretary）
  → AgentConversation(channel=<channel_type>, staff_id=operator_id) 复用或新建
  → AgentRuntime::run（编排）→ AgentChatClient（推理）→ AgentToolExecutor（工具执行），非流式，IM 无流式语义
  → Channel.sendMessage 回复（超长回复分段，按频道渲染适配，见下）
```

**按频道渲染适配（已实施，`MarkdownAdapter`）**：AI 回复的 Markdown 不再一律降级纯文本，
而是转换到各频道原生富文本能力，转换/发送失败时回退纯文本（宁可丢格式不可丢消息）：

- **Telegram**：`toTelegramHtml`（HTML parse_mode，≤4000 字符整发；被平台拒绝时回退 `toPlain` 重发一次；超长直接纯文本分段）
- **企业微信**：`toWechatWorkMarkdown`（企微 markdown 子集：斜体/删除线降级、围栏代码降级引用块、图片降级链接），按 2000 字节分段逐段 `sendMarkdown`，失败段回退 `sendText` 纯文本
- 未适配频道：`toPlain` 纯文本兜底

- webhook 路由无认证但强制验签，验签失败 403 并记日志
- AI 执行放队列 Job（IM 平台要求收到即 ACK，同步跑 ReAct 会超时）：
  落消息即 ACK → `ProcessIbotInboundMessage` Job 执行 → bot 主动推结果
- 会话记忆与 Web 端一致（同一 AI 会话体系）；IM 会话与 Web 会话独立，不合并
- **现状**：Telegram long polling 由 artisan 常驻命令 `ibot:telegram:poll` 承载（Connector 就绪后迁入）；
  企微 webhook 路由 `GET/POST /api/v1/public/ibot/webhook/wechat-work/{ibotId}`（验签 + AES 加解密）

### Connector（长连接与私有 API 的常驻承载，对齐 OpenClaw Gateway + 插件）⏳ 未实施（Phase 2）

webhook 频道（企微/微信客服/TG webhook）平台直推 PHP，不经 Connector；
长连接与 iLink 频道需要常驻进程，由独立 Node.js Connector 承载：

- 承载内容：Telegram long polling（默认传输）、飞书 WS 事件订阅、钉钉 Stream、微信 iLink sidecar
- 出站连接的可靠性自理：TG 轮询单实例约束（每 token 仅一个活跃轮询器）+ 看门狗（120s 无活性重启）；
  WS/Stream 掉线重连退避；均对齐 OpenClaw 各插件的实现经验
- **Connector 是哑管道，大脑永远在框架**：收消息 → 归一后 POST 框架 IbotGateway（带签名，
  与 webhook 链路完全同构）；框架出方向消息经 Connector 本地 API 发出。
  Connector 不携带任何 AI/业务逻辑，可随时重启替换
- 通道插件化（对齐 OpenClaw plugins）：每个平台一个 connector 插件，新增平台不改核心
- 长连接 session（飞书 WS 凭证、iLink 登录态）持久化在 Connector 侧；掉线重连、
  登录二维码刷新由 Connector 自理，状态经心跳回写 ibots.status
- 不内嵌 OpenClaw 本体（单用户自托管设计、自带 agent 循环，与多租户 SaaS 不匹配）：
  借其 Gateway + channel 插件架构，Connector 只做传输

### 出（系统 → operator）

- Notification 新增 **ibot 通道驱动**：`via()` 返回 ibot 时，按 operator 的默认通道绑定推送
- 未绑定或推送失败时降级 database + mail（fail-open，通知永不丢）
- 这就是「默认消息通道」的落点：task-chain 的待办、event-plan 的 require_confirm 待确认、
  系统告警，统一经此出口触达 operator 的 IM

## 六、L2 写操作：IM 内文本确认 ✅ 已实施

IM 没有 Web 端的确认卡片，采用文本确认协议（`ProcessIbotInboundMessage` 内实现）：

```
AI：即将执行【创建优惠券】
coupon_name: 满100减20
amount: 1000

回复"确认"执行，回复其他内容取消（10 分钟内有效）。
operator：确认
AI：已创建 ✓ 优惠券 ID 8821
```

实现要点：

- `AgentRuntime::run()` 支持 `options['intercept_l2']`（opt-in，仅 ibot 开启）：L2 工具由 AgentToolExecutor 拦截，不直接执行，
  经 `partitionByRisk()` 分级后签发确认令牌，返回 `finish_reason='pending_confirmation'` + `pendingConfirmations` 载荷
- 确认载荷写入 `AgentConversation.metadata['ibot_pending_confirm']`（token/args_hash/tool_slug/tool_name/arguments/expires_in）
- 入站消息先查 pending：确认词精确匹配（trim 后 `确认` 或不分大小写 `yes`）→ consume → 权限校验（tenant_admin）→ ToolRegistry::execute → continueWithToolResults 让 LLM 收尾
- 非确认词 → consume 作废令牌 → 审计 ai_action_cancel → 回发「已取消【工具名】」→ 该消息作为新输入继续 run()
- 令牌过期/无效 → 清 metadata → 回发过期提示 → 消息作为新输入继续
- TTL 配置：`config('ai.ibot.confirm_ttl')`（env `AI_IBOT_CONFIRM_TTL`，默认 600s）
- 同轮多个 L2：全部签发，IM 侧只取第一个，回复中附「一次只能确认一个操作，其余请分步发起」
- 审计动作对齐 Web 端：`ai_action_execute` / `ai_action_cancel`（resource_type=agent_tool）

## 七、与既有通道代码的关系（ibot 独立，不背 Channel 收敛使命）

ibot 与频道会话/聊天消息体系完全是两件事：

| | ibot | Channel/Conversation 体系 |
|---|---|---|
| 服务对象 | Operator（个人 AI 通信助理） | User（客服/营销消息） |
| 消息落点 | agent_conversations（AI 会话） | Conversation 模块 Message（客服会话） |
| 实现位置 | 框架 `src/Modules/Ibot/`（独立新模块） | scrm Channel 模块（现状保留） |

因此：

1. **Ibot 是独立模块**，不复用、不改造、不收敛既有 Channel 代码；`IbotChannelContract`
  （verifyInbound / parseInbound / sendMessage）自成体系，与 `ChannelContract` 无继承关系
2. 框架 `src/Services/Channel/` 经审计为死代码（无容器绑定、无 register 调用、
  routes/api.php 两条 webhook 调用不存在的 `routeMessage()` 方法），作为**独立卫生事项**清理，
  不绑定在 ibot 里程碑上
3. scrm Channel 模块（活码/欢迎语/模板消息/客服 AI）现状保留；将来全渠道客服收件箱若启动，
  另行设计，不与 ibot 混同

## 八、管理与工具契约（已实施，与原设计有调整）

### 控制台配置中心（console 「随身助理」页 `/ibot-settings`）

- 租户管理员（`rbac.permission:setting.update`）：ibots CRUD，管理 API `api/v1/tenant/ibot/ibots*`
  （`IbotAdminController`）：凭证脱敏回显（`****` + 尾 4 位，提交时掩码值视为未修改）、
  局部合并更新、启停、删除保护（存在 active 绑定时拒删）
- operator 个人（`api/v1/tenants/{tenantId}/ibot/*`，`IbotBindingController`）：
  查各频道绑定状态、生成绑定码、设默认通道、解绑

### 秘书工具（实际落地：AI 引导配置三件套，非原设计三工具）

| slug | risk | 语义 |
|---|---|---|
| `ibot_setup_status` | L1 | 各频道配置/绑定状态总览，给 AI 判断下一步引导话术 |
| `save_ibot_config` | **L2** | 保存/更新频道凭证（字段白名单，仅 telegram/wechat_work） |
| `generate_ibot_bind_code` | **L2** | 生成绑定码（TG 附 `t.me/<bot>?start=<码>` 链接） |

> 原设计的 `ibot_list_bindings` / `ibot_generate_bind_qr` / `ibot_set_default_channel` 未实现：
> 绑定状态已并入 `ibot_setup_status`，生码已由 `generate_ibot_bind_code` 覆盖，
> 设默认通道目前仅控制台操作（低频动作，暂不工具化）

## 九、分期实施与验收

**Phase 0（骨架 + Telegram 全链路）✅ 已上线**：两表迁移 + IbotGateway + TelegramChannel
（long polling，artisan `ibot:telegram:poll` 常驻承载）+ 绑定码流程 + 入方向 Job 化执行。
验收已达成：生产 TG bot 扫码绑定并完成 L1 查询对话往返。

**Phase 1（企微 + 确认与通知）✅ 已上线**：WechatWorkChannel
（webhook，Token+EncodingAESKey 加解密，共享 SDK `WechatWorkApiClient`）+
Notification ibot 驱动（`IbotNotificationChannel` + `IbotNotifier`，未绑定/失败由 database/mail 兜底）+
默认通道设定 + 管理页 + L2 IM 内文本确认（第六节）。
追加交付（超出原计划）：配置中心页 + 管理 API + AI 引导配置三工具 + MarkdownAdapter 按频道渲染。

**Phase 2（Connector + 微信个人号 iLink）⏳ 未启动**：Node Connector（插件化通道承载）+
微信 iLink 通道（扫码登录、凭证持久化、掉线告警）+ 配对管理页。
验收：手机微信扫码完成 iLink 登录，operator 加好友绑定后全链路对话往返；
Connector 重启后 session 恢复、消息不丢；Connector 关闭不影响 webhook 频道。

**Phase 3（飞书 WS + 钉钉 Stream + 微信客服）⏳ 未启动**：Connector 新增飞书 WS / 钉钉 Stream 插件
（钉钉仅 Stream，5 凭证配置）+ 微信客服 webhook Provider；QQ 视社区插件成熟度评估预留。
验收：五平台六频道全通；飞书/钉钉在无公网回调配置下（纯出站长连接）正常收发。

另行（不卡 ibot 里程碑）：框架 `src/Services/Channel/` 死代码与 routes/api.php 两条坏 webhook
路由的清理，作为独立卫生 PR 执行。

通用验收标准：

- ibot 关闭（`AI_IBOT_ENABLED=false`）时，Web 助手、通知（降级 database/mail）、业务模块完全不受影响；
  Connector 宕机只影响长连接/iLink 频道，webhook 频道照常
- 所有 ibots / bindings 记录带 tenant_id，跨租户不可见；binding 的 external_id 不跨 bot 复用
- L2 工具在 IM 内绕过文本确认视为阻断性缺陷
- webhook 未验签放行视为阻断性缺陷
