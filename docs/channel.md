# Channel 抽象层 + 企业微信会话接入设计

> Channel 是 Conversation 的数据源抽象——任何外部 IM 平台（企微、公众号、小程序、Slack、Telegram…）都是一个 Channel Provider，框架提供统一抽象层处理「接收 -> 解析 -> 存储 -> 事件」全链路，下游项目只做事件消费。

## 设计哲学

与 Ibot 的本质区别：

- **Channel**：服务对象是 User（被服务的人），消息进 `conversations` 表
- **Ibot**：服务对象是 Operator（内部人），消息进 `agent_conversations` 表，完全独立体系

分层架构：

```
下游项目（SCRM 等）—— 仅事件消费层
  Listener: MessageReceived -> 业务逻辑（客户关联 / 自动回复 / AI 客服 / 活码 / 欢迎语）
                          ↑ MessageReceived 事件
框架 Channel 模块（全链路基础设施）
  Webhook Route  ->  ChannelManager（租户感知工厂）  ->  ChannelContract（Provider）
  ->  MessageRouter（归一化）  ->  ConversationRouter（会话策略）  ->  EventBusBridge（入库+事件）
数据层（已有）
  tenant_settings（group=channel，凭证加密） / conversations / messages / participants
```

## 零、基线现状（已完成）

- 共享 SDK 层 `src/Support/WechatWork/`：`WechatWorkCrypto`（验签+AES解密）、`WechatWorkApiClient`（token 按 corp+agent 缓存 + message/send + markdown）、`SessionArchiveService`/`ArchiveDecryptor`（会话存档解密，已存在但接收链路未接）。
- 目录已重组：Provider 集中在 `src/Services/Channel/Providers/`，平台协议归 `src/Support/{Platform}/`。
- Ibot 已跑通（operator 助理，独立体系），其 webhook 模式作为参照：GET 验证 / POST 验签解密 / 收到即 ACK / AI 走队列。
- 数据层已就绪：`tenant_settings`（凭证加密）、`conversations`（type=support/group/direct, channel, metadata json）、`messages`、`participants`。

## 一、企微通信能力与协议矩阵（设计依据）

企微对 Channel 而言不是单一驱动，按协议拆：

| 场景 | 协议/凭证 | 接收 | 发送 | 驱动 |
|---|---|---|---|---|
| 内部单聊（员工↔应用） | 自建应用 secret + XML 加密回调 | 应用回调 | message/send | enterprise_wechat_app |
| 内部群聊（应用→群） | 自建应用 + appchat | 应用回调（群消息） | appchat/send（chatid） | enterprise_wechat_app |
| 外部客户单聊（微信用户） | 客服 secret + JSON 回调 | kf 回调 / 存档 | kf/send_msg | enterprise_wechat_kf |
| 客户群（含外部成员） | 客服 / 外部联系 | kf 回调 / 存档 | 受限（群发/欢迎语/客服消息） | enterprise_wechat_kf |
| 普通外部微信群 | 无 | 无 | 无 | 不支持 |

接收策略（外部场景，可插拔）：**有会话存档走存档（SessionArchiveService 拉取解密），无存档走客服（kf 回调）**。本期实现客服路径，存档路径预留接口、后续接入。内部场景固定走自建应用回调。

## 二、核心抽象设计（渠道无关）

### 2.1 ChannelContract（重写，替换现有 4 方法脱节版）

```php
interface ChannelContract
{
    /** 渠道类型标识，如 enterprise_wechat_app / enterprise_wechat_kf */
    public function type(): string;

    /** URL 验证：验签 + 解密 echostr 返回明文（GET） */
    public function verifyUrl(array $query): ?string;

    /** 消息回调验签（POST） */
    public function verifySignature(array $query, string $rawBody): bool;

    /** 从原始回调体解析出归一化入向消息；非消息事件/不支持类型返回 null */
    public function parseInbound(string $rawBody, array $query): ?InboundMessage;

    /** 向会话发送消息（按会话类型路由到 message/send / appchat / kf） */
    public function sendMessage(Conversation $conversation, array $message): bool;
}
```

### 2.2 InboundMessage DTO（新建 `src/DTOs/InboundMessage.php`）

替代松散的 onMessage 数组，统一表达单聊/群聊、内部/外部：

```php
final class InboundMessage {
    public function __construct(
        public readonly string $channel,          // enterprise_wechat_app / _kf
        public readonly string $conversationType, // direct | group
        public readonly string $externalConvId,   // 单聊=对端userid/open_kfid；群=chatid
        public readonly string $senderExternalId, // 发送者平台身份（群聊区分发言人）
        public readonly string $senderType,       // internal | external
        public readonly string $msgType,          // text | image | voice | event ...
        public readonly string $content,
        public readonly ?string $platformMsgId,
        public readonly array $raw = [],
    ) {}
}
```

### 2.3 ChannelManager（改造为租户感知工厂）

```php
class ChannelManager {
    protected array $drivers = [
        'enterprise_wechat_app' => EnterpriseWechatAppDriver::class,
        'enterprise_wechat_kf'  => EnterpriseWechatKfDriver::class,
        // wechat_official / slack / dingtalk 后续
    ];
    public function resolve(string $type, int $tenantId): ChannelContract; // 从 TenantSetting 读凭证实例化
    public function extend(string $type, string $class): void;             // 下游扩展
    public function enabledChannels(int $tenantId): array;
}
```

凭证约定：`tenant_settings` group=`channel`，key=`{type}`（如 `enterprise_wechat_app`），value=JSON 加密。

- app：`{corp_id, corp_secret, agent_id, token, encoding_aes_key, enabled}`
- kf：`{corp_id, kf_secret, token, encoding_aes_key, enabled}`

### 2.4 ConversationRouter（新建 `src/Services/Channel/ConversationRouter.php`）

```php
class ConversationRouter {
    /** 按 (tenant, channel, external_conv_id) 找 active 会话，不存在则创建 */
    public function resolve(int $tenantId, InboundMessage $msg): Conversation;
}
```

- 匹配键存 `conversations.metadata.external_conv_id`（单聊=对端身份，群=chatid），`channel`+`type`(direct/group) 区分。
- 群聊创建时 type=group、title 取群名；单聊 type=direct。
- 外部身份建模：`messages.sender_id` 为 bigint（FK 语义），外部发送者不落此列——`sender_type='external'`、`sender_id=null`、平台身份存 `metadata.external_from`；`participants` 仅挂内部成员（user_id NOT NULL + FK users），外部成员不进 participants。

### 2.5 MessageRouter（修复）+ EventBusBridge（增强）

- MessageRouter：删除死代码 routeMessage 调用；route() 改为消费 `InboundMessage`（不再依赖 provider 返回数组的字段名错位 msg_id/from_user）。
- EventBusBridge.dispatch：接收 `(InboundMessage, Conversation)`，入库时填 conversation_id、按 senderType 处理 sender_id/external_from，更新 conversation.last_message_at/message_count，再触发 `MessageReceived`。

### 2.6 ChannelWebhookController + 统一路由（替换 routes/api.php 死代码）

```php
Route::match(['get','post'], 'v1/channels/{type}/webhook/{tenant_slug?}', ChannelWebhookController::class);
```

控制器流程（渠道无关，复刻 ibot 成熟模式）：

```
解析 type + tenant_slug -> ChannelManager.resolve(type, tenantId)
GET  -> provider.verifyUrl(query) -> 回显明文 echostr（text/plain）
POST -> provider.verifySignature(query, rawBody) [失败 403]
     -> provider.parseInbound(rawBody, query) [null 则直接 ACK]
     -> ConversationRouter.resolve(tenantId, msg)
     -> EventBusBridge.dispatch(msg, conversation)
     -> 收到即 ACK（空串 200，避免企微重试）
```

webhook 无认证上下文，租户解析硬豁免 TenantScope（参照 IbotWebhookController::loadIbot）。

### 2.7 容器注册

新建 `src/Services/Channel/ChannelServiceProvider.php`（或并入 TenancyServiceProvider）：单例注册 ChannelManager / MessageRouter / ConversationRouter / EventBusBridge，加载统一路由。

## 三、分阶段实施

### Phase 0：Channel 抽象层激活（渠道无关）

| # | 操作 | 文件 |
|---|---|---|
| 1 | 重写 ChannelContract | `src/Contracts/ChannelContract.php` |
| 2 | 新建 InboundMessage DTO | `src/DTOs/InboundMessage.php` |
| 3 | ChannelManager 改造为租户感知工厂 | `src/Services/Channel/ChannelManager.php` |
| 4 | 新建 ConversationRouter（含外部身份建模） | `src/Services/Channel/ConversationRouter.php` |
| 5 | MessageRouter 修复（消费 InboundMessage） | `src/Services/Channel/MessageRouter.php` |
| 6 | EventBusBridge 增强（注入 conversation + 外部身份） | `src/Services/Channel/EventBusBridge.php` |
| 7 | 新建 ChannelWebhookController | `src/Http/Controllers/ChannelWebhookController.php` |
| 8 | 统一参数化路由替换死代码 | `routes/api.php` |
| 9 | 容器注册 + 路由加载 | 新建 ChannelServiceProvider |

### Phase 1：企业微信自建应用驱动（内部，完整双向）

| # | 操作 |
|---|---|
| 1 | 现 `EnterpriseWechatProvider` 重构为 `EnterpriseWechatAppDriver`，对齐新 ChannelContract（verifyUrl/verifySignature/parseInbound/sendMessage） |
| 2 | parseInbound 产出 InboundMessage：单聊 conversationType=direct；群聊（ChatId 存在）=group，senderExternalId=FromUserName |
| 3 | WechatWorkApiClient 扩展 `sendGroupMessage(chatid, message)`（appchat/send）；sendMessage 按会话类型分发 |
| 4 | 凭证约定落地 + TenantSetting 读取 |
| 5 | 测试：GET 验证 / POST 单聊 / POST 群聊 / 发送分发 / 会话路由复用 |

### Phase 2：企业微信客服驱动（外部客户）+ 接收策略可插拔

| # | 操作 |
|---|---|
| 1 | WechatWorkApiClient 扩展 kf API：kf 令牌（corp+kf_secret）、kf/send_msg、kf/sync_msg（拉消息） |
| 2 | 新建 `EnterpriseWechatKfDriver`：JSON 回调解析（非 XML）、open_kfid/external_userid 提取、kf/send_msg 发送 |
| 3 | 接收策略抽象 `InboundSourceContract`：`KfCallbackSource`（本期）/ `ArchiveSource`（预留，基于 SessionArchiveService，后续）；按租户配置 `receive_strategy=archive|kf` 选择（默认 kf） |
| 4 | 客户群：type=group，发送走客服消息/群发（能力受限，明确标注） |
| 5 | 测试：kf URL 验证 / JSON 消息解析 / 发送 / 策略选择 |

### Phase 3：SCRM 事件消费 + 清理

| # | 操作 |
|---|---|
| 1 | SCRM 新建 MessageReceived Listener（客户关联 / 自动回复 / AI 路由） |
| 2 | SCRM send_message 工具 handler 改调框架 ChannelManager |
| 3 | 移除 SCRM 重复代码（ChannelProvider 接口 / WechatWorkProvider / ChannelCallbackController / 回调路由） |

## 四、与 Ibot 的边界（不变，且本设计对 ibot 零影响）

隔离依据：ibot 用独立 IbotChannelContract / IbotChannelResolver / IbotGateway，路由前缀 `/ibot/webhook/...`（与 `/channels/...` 不冲突），消息进 agent_conversations，凭证读 ibots.credentials——本设计改动的 ChannelContract/ChannelManager/路由/conversations 均不触及 ibot。

唯一共享接触点：`Support/WechatWork` SDK（ibot 调用 sendText/sendMarkdown/accessToken/crypto）。

**硬约束：对共享 SDK 文件只做新增方法（sendGroupMessage、kf 系列），严禁修改现有方法签名/行为/缓存键，确保 ibot 回归零变化。** 每个 Phase 收尾跑 ibot 全套测试（WechatWorkChannelTest / IbotWebhookControllerTest 等）验证无回归。

| 维度 | Ibot | Channel 会话 |
|---|---|---|
| 服务对象 | Operator | User |
| 消息存储 | agent_conversations | conversations |
| 凭证来源 | ibots.credentials | tenant_settings group=channel |
| 回调入口 | per-bot webhook | 统一 /v1/channels/{type}/webhook/{slug} |
| 共享 | 共用 Support/WechatWork SDK（crypto/api token），体系互不干扰 |

## 五、设计决策记录

| 决策 | 结论 | 原因 |
|---|---|---|
| 企微驱动拆分 | app + kf 两个驱动 | 自建应用（XML+message/send）与客服（JSON+kf）是两套协议、两套 token scope |
| 外部接收策略 | 可插拔：存档优先、客服兜底 | 存档付费门槛高（大企业），客服普惠；框架按租户配置选择 |
| 本期接收实现 | 客服（kf）路径，存档预留接口 | 先客服，存档以后 |
| 内部场景 | 自建应用回调，完整双向 | 同 ibot 协议，SDK 零新增，最小成本打通全链路 |
| 外部身份建模 | sender_type=external + metadata.external_from，不进 participants | messages.sender_id/participants.user_id 是 bigint FK users，外部平台身份非系统 User |
| 群聊类型 | conversations.type=group + metadata.external_conv_id=chatid | 表结构已支持，无需改表 |
| 多租户识别 | URL path 带 tenant_slug | 避免全库扫描 |
| 凭证存储 | 框架 tenant_settings | 租户配置是框架核心能力 |
