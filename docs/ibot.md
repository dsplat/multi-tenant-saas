# AI 消息小助理（ibot）设计规范

> 状态：设计稿（插队任务，优先于 task-chain / event-plan 实施）
> 关联：`docs/task-chain.md` · `docs/event-plan.md` · AI 小助手完整化计划
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
- **执行永远经既定链路**：IM 消息进来走同一个 AgentRuntime + ToolRegistry，不另建 AI 调用路径
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
| is_default_channel | boolean | 默认消息通道（每 operator 至多一个 true） |
| status | string | pending（码已发未扫）/ active / revoked |
| bound_at | timestamp nullable | |

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
  → AgentRuntime::run（非流式，IM 无流式语义）
  → Provider.sendMessage 回复（超长回复分段，Markdown 按平台能力降级为纯文本）
```

- webhook 路由无认证但强制验签，验签失败 403 并记日志
- AI 执行放队列 Job（IM 平台要求收到即 ACK，同步跑 ReAct 会超时）：
  落消息即 ACK → Job 执行 → bot 主动推结果
- 会话记忆与 Web 端一致（同一 AI 会话体系）；IM 会话与 Web 会话独立，不合并

### Connector（长连接与私有 API 的常驻承载，对齐 OpenClaw Gateway + 插件）

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

## 六、L2 写操作：IM 内文本确认

IM 没有 Web 端的确认卡片，采用文本确认协议：

```
AI：即将执行【创建优惠券：满100减20，1000张，7天有效】，回复"确认"执行，回复其他内容取消。
operator：确认
AI：已创建 ✓ 优惠券 ID 8821
```

- 沿用 ActionConfirmService 的令牌机制（签发/一次性消费/args_hash 防篡改），仅把"点卡片"换成"回复确认词"
- TTL 放宽到 IM 场景合理值（10 分钟），超时后回复"确认"提示已过期需重新发起
- 确认词命中采用精确匹配（"确认"/"yes"），其余任何回复视为取消——宁可误取消不可误执行

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

## 八、管理与工具契约

控制台「消息小助理」管理页（框架层，admin/console 共用）：

- 租户管理员：ibots CRUD（配置各平台凭证、选人格 agent、启停）
- operator 个人：查看各频道绑定状态、生成绑定二维码、设默认通道、解绑

秘书工具（Web 端助手也能管理 ibot）：

| slug | risk | 语义 |
|---|---|---|
| `ibot_list_bindings` | L1 | 我的各频道绑定状态与默认通道 |
| `ibot_generate_bind_qr` | L1 | 生成指定频道的绑定二维码 |
| `ibot_set_default_channel` | **L2** | 设定默认消息通道 |

## 九、分期实施与验收

**Phase 0（骨架 + Telegram 全链路）**：两表迁移 + IbotGateway + TelegramProvider（默认 long polling，
先用 artisan 常驻命令承载、Connector 就绪后迁入；生产可切 webhook）+ 绑定码流程 + 入方向 Job 化执行。
验收：BotFather 建一个 bot，无公网回调配置下 operator 扫码绑定并在 TG 内完成一次 L1 查询对话往返。

**Phase 1（企微 + 确认与通知）**：WechatWorkProvider（webhook，Token+EncodingAESKey 加解密、
可信 IP 配置指引与连通自检工具，防静默失败）+ L2 文本确认 +
Notification ibot 驱动 + 默认通道设定 + 管理页。
验收：企微内扫码绑定并对话；L2 操作出现文本确认且回复非确认词即取消；
一条系统通知经默认通道推达 IM。

**Phase 2（Connector + 微信个人号 iLink）**：Node Connector（插件化通道承载）+
微信 iLink 通道（扫码登录、凭证持久化、掉线告警）+ 配对管理页。
验收：手机微信扫码完成 iLink 登录，operator 加好友绑定后全链路对话往返；
Connector 重启后 session 恢复、消息不丢；Connector 关闭不影响 webhook 频道。

**Phase 3（飞书 WS + 钉钉 Stream + 微信客服）**：Connector 新增飞书 WS / 钉钉 Stream 插件
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
