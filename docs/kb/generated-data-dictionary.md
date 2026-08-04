---
title: 数据字典
module: 
audience: internal
locale: zh
version: 
---

# 数据字典

> 本文档由 `secretary:kb:generate` 自动生成，请勿手工编辑。

## agent_conversation_messages

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| message_id | bigint unsigned | 否 | - | 消息 ID（IdGenerator 全局ID） |
| conversation_id | bigint unsigned | 否 | - | 会话 ID |
| role | enum('user','assistant','tool','system') | 否 | - | 消息角色 |
| content | text | 是 | - | 消息内容 |
| tool_calls | json | 是 | - | 工具调用（OpenAI 结构） |
| tool_call_id | varchar(100) | 是 | - | 工具调用 ID（tool 角色消息） |
| metadata | json | 是 | - | 元数据 |
| created_at | timestamp | 是 | - | 创建时间 |

## agent_conversations

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| conversation_id | bigint unsigned | 否 | - | 会话 ID（IdGenerator 全局ID） |
| agent_id | bigint unsigned | 否 | - | Agent ID |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| customer_id | bigint unsigned | 是 | - | 客户ID（业务层） |
| staff_id | bigint unsigned | 是 | - | 坐席ID（业务层） |
| channel | varchar(20) | 否 | web | 会话渠道 |
| subject | varchar(255) | 是 | - | 会话主题 |
| status | varchar(20) | 否 | active | 会话状态 |
| summary | text | 是 | - | 会话摘要 |
| token_usage | json | 是 | - | Token 用量统计 |
| message_count | int | 否 | 0 | 消息计数 |
| metadata | json | 是 | - | 元数据 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## agent_tool_logs

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| log_id | bigint unsigned | 否 | - | 日志 ID（IdGenerator 全局ID） |
| conversation_id | bigint unsigned | 否 | - | 会话 ID |
| agent_id | bigint unsigned | 否 | - | Agent ID |
| tool_name | varchar(100) | 否 | - | 工具名称 |
| input | json | 是 | - | 工具输入参数 |
| output | json | 是 | - | 工具输出 |
| duration_ms | int | 否 | 0 | 执行耗时（毫秒） |
| status | varchar(20) | 否 | success | 调用状态 |
| error | text | 是 | - | 错误信息 |
| created_at | timestamp | 是 | - | 创建时间 |

## agent_tools

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| tool_id | bigint unsigned | 否 | - | 工具 ID（IdGenerator 全局ID） |
| tenant_id | bigint unsigned | 否 | 0 | 租户ID（0=全局工具） |
| name | varchar(100) | 否 | - | 工具名称 |
| slug | varchar(100) | 否 | - | 工具唯一标识 |
| description | text | 否 | - | 工具描述 |
| category | varchar(50) | 是 | - | 工具分类 |
| parameters_schema | json | 否 | - | 参数 JSON Schema |
| handler_class | varchar(255) | 否 | - | 处理类全限定名 |
| enabled | tinyint(1) | 否 | 1 | 是否启用 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## agent_workflows

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| agent_id | bigint unsigned | 否 | - | Agent ID |
| workflow_id | bigint unsigned | 否 | - | Workflow ID |
| is_primary | tinyint(1) | 否 | 0 | 是否主工作流 |
| sort_order | int | 否 | 0 | 排序 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## agents

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| agent_id | bigint unsigned | 否 | - | Agent ID（IdGenerator 全局ID） |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| name | varchar(100) | 否 | - | Agent 名称 |
| role | varchar(50) | 否 | - | 角色标识 |
| avatar | varchar(500) | 是 | - | 头像 URL |
| system_prompt | text | 否 | - | 系统提示词 |
| description | text | 是 | - | 描述 |
| tools | json | 是 | - | 工具 slug 列表 |
| kb_ids | json | 是 | - | 知识库 ID 列表 |
| feature_keys | json | 是 | - | 映射的 AI 功能点列表（业务层使用） |
| model_config | json | 否 | _utf8mb4\'{}\' | 模型配置 JSON |
| enabled | tinyint(1) | 否 | 1 | 是否启用 |
| is_builtin | tinyint(1) | 否 | 0 | 是否内置 |
| metadata | json | 是 | - | 元数据 |
| version | int | 否 | 1 | 版本号 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## ai_model_aliases

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| alias_id | bigint unsigned | 否 | - | 别名ID（全局ID，16位数字） |
| alias | varchar(100) | 否 | - | 模型别名（友好名称） |
| actual_model | varchar(100) | 否 | - | 实际模型名（对应 AiModelEnum 值或自定义模型） |
| provider | varchar(50) | 是 | - | 提供商标识（可选，用于约束/路由） |
| type | varchar(20) | 否 | - | 类型: text/image/video |
| is_active | tinyint(1) | 否 | 1 | 是否激活 |
| is_deprecated | tinyint(1) | 否 | 0 | 废弃标记 |
| description | varchar(255) | 是 | - | 说明 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## ai_prompts

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| prompt_id | bigint unsigned | 否 | - | 提示词ID（全局ID，16位数字） |
| tenant_id | bigint unsigned | 是 | - | 租户ID，null 表示系统级模板 |
| name | varchar(100) | 否 | - | 模板名称（同租户内唯一，租户可同名覆盖系统级） |
| category | varchar(50) | 否 | general | 分类 |
| system_prompt | text | 是 | - | 系统提示词 |
| user_prompt | text | 是 | - | 用户提示词模板（含 {{变量}} 占位符） |
| variables | json | 是 | - | 变量定义 JSON：[{name,description,required}] |
| version | int unsigned | 否 | 1 | 版本号 |
| status | varchar(20) | 否 | active | 状态: active/inactive |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## ai_providers

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| provider_id | bigint unsigned | 否 | - | 提供商ID（全局ID，16位数字） |
| tenant_id | bigint unsigned | 是 | - | 租户ID，null 表示系统级配置 |
| code | varchar(50) | 否 | - | 提供商标识（openai/zhipu/anthropic 等），对应 config(ai.providers) 键名 |
| name | varchar(100) | 否 | - | 提供商显示名称 |
| base_url | varchar(255) | 是 | - | API 基地址 |
| api_key | text | 是 | - | 默认 API Key（加密存储） |
| status | varchar(20) | 否 | active | 状态: active/inactive |
| priority | smallint | 否 | 0 | 优先级，数字越小越优先 |
| metadata | json | 是 | - | 扩展配置（超时、额外参数等） |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## ai_requests

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| request_id | bigint unsigned | 否 | - | 请求ID（全局ID，16位数字） |
| tenant_id | bigint unsigned | 是 | - | 租户ID，实现租户隔离 |
| user_id | bigint unsigned | 是 | - | 用户ID |
| model | varchar(100) | 否 | - | 模型名（对应 AiModelEnum 值或自定义模型） |
| provider | varchar(50) | 否 | - | 提供商标识 |
| prompt_summary | text | 是 | - | 请求内容摘要 |
| input_tokens | int unsigned | 否 | 0 | 输入 Token 用量 |
| output_tokens | int unsigned | 否 | 0 | 输出 Token 用量 |
| response_time_ms | int unsigned | 是 | - | 响应时间（毫秒） |
| cost | decimal(12,6) | 否 | 0.000000 | 费用 |
| status | varchar(20) | 否 | pending | 状态: pending/success/failed |
| error_message | text | 是 | - | 错误信息（失败时） |
| metadata | json | 是 | - | 扩展元数据（finish_reason、options 摘要等） |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## ai_tenant_configs

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| ai_tenant_config_id | bigint unsigned | 否 | - | 配置ID（全局ID，16位数字） |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| text_enabled | tinyint(1) | 否 | 1 | 是否启用文本 AI |
| image_enabled | tinyint(1) | 否 | 1 | 是否启用图片 AI |
| video_enabled | tinyint(1) | 否 | 1 | 是否启用视频 AI |
| custom_api_keys | json | 是 | - | 自定义 API Key：{provider: key}，覆盖系统默认 |
| allowed_models | json | 是 | - | 允许租户使用的模型列表，null 表示继承系统默认 |
| monthly_budget_limit | decimal(12,2) | 否 | 0.00 | 月度预算上限（0 表示不限） |
| overage_action | varchar(20) | 否 | block | 超额处理: block/warn/allow |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## ai_usage_quotas

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| ai_usage_quota_id | bigint unsigned | 否 | - | 配额ID（全局ID，16位数字） |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| subscription_plan_id | bigint unsigned | 是 | - | 套餐ID |
| text_token_limit | bigint unsigned | 否 | 0 | 文本 Token 月度上限（0 表示不限） |
| image_generation_limit | bigint unsigned | 否 | 0 | 图片生成月度上限（0 表示不限） |
| video_duration_limit | bigint unsigned | 否 | 0 | 视频时长月度上限（秒，0 表示不限） |
| period | varchar(20) | 否 | monthly | 计费周期标识，如 monthly:2026-06 |
| used_tokens | bigint unsigned | 否 | 0 | 已用 Token 数 |
| used_images | bigint unsigned | 否 | 0 | 已生成图片数 |
| used_video_seconds | bigint unsigned | 否 | 0 | 已生成视频时长（秒） |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## alert_rules

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - |  |
| name | varchar(100) | 否 | - |  |
| metric | varchar(100) | 否 | - |  |
| operator | varchar(10) | 否 | > |  |
| threshold | double | 否 | 0 |  |
| severity | varchar(20) | 否 | warning |  |
| channels | json | 是 | - |  |
| cooldown_sec | int | 否 | 300 |  |
| enabled | tinyint(1) | 否 | 1 |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## alerts

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - |  |
| rule_name | varchar(100) | 否 | - |  |
| severity | varchar(20) | 否 | - |  |
| message | text | 否 | - |  |
| context | json | 是 | - |  |
| triggered_at | timestamp | 否 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## api_versions

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | bigint unsigned | 否 | - |  |
| version | varchar(20) | 否 | - |  |
| status | varchar(20) | 否 | stable |  |
| release_date | date | 是 | - |  |
| sunset_date | date | 是 | - |  |
| notes | text | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## archived_messages

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| archived_message_id | bigint unsigned | 否 | - | 存档消息ID |
| tenant_id | bigint unsigned | 是 | - | 租户ID |
| msg_id | varchar(128) | 否 | - | 企业微信消息ID |
| room_id | varchar(128) | 否 | - | 群聊/会话ID |
| msg_type | varchar(32) | 否 | text | 消息类型 |
| from_user | varchar(128) | 否 |  | 发送者UserID |
| content | json | 是 | - | 解密后的消息内容 |
| raw_data | json | 是 | - | 原始API返回数据 |
| seq | bigint unsigned | 否 | 0 | 消息序列号 |
| create_time | timestamp | 是 | - | 消息创建时间 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## attachments

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| attachment_id | bigint unsigned | 否 | - | 附件 ID（IdGenerator 全局ID） |
| conversation_id | bigint unsigned | 否 | - | 会话 ID |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| uploaded_by | bigint unsigned | 否 | - | 上传者用户ID |
| file_upload_id | bigint unsigned | 是 | - | 关联的文件上传 ID |
| filename | varchar(255) | 否 | - | 原始文件名 |
| mime_type | varchar(100) | 是 | - | MIME 类型 |
| size | bigint unsigned | 否 | 0 | 文件大小（字节） |
| disk | varchar(20) | 否 | local | 存储磁盘: local/s3/oss |
| path | varchar(500) | 否 | - | 存储路径 |
| metadata | json | 是 | - | 元数据 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## audit_logs

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| log_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - |  |
| user_id | bigint unsigned | 是 | - |  |
| action | varchar(50) | 否 | - |  |
| resource_type | varchar(50) | 否 | - |  |
| resource_id | bigint unsigned | 是 | - |  |
| old_values | json | 是 | - |  |
| new_values | json | 是 | - |  |
| ip_address | varchar(45) | 是 | - |  |
| user_agent | varchar(500) | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## branding_configs

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| branding_config_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - |  |
| logo_url | varchar(500) | 是 | - |  |
| favicon_url | varchar(500) | 是 | - |  |
| primary_color | varchar(20) | 是 | - |  |
| secondary_color | varchar(20) | 是 | - |  |
| custom_css | text | 是 | - |  |
| custom_domain | varchar(200) | 是 | - | 自定义域名 |
| login_page_style | varchar(20) | 否 | default | 登录页样式 |
| email_template | varchar(50) | 否 | default | 邮件模板品牌化 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |
| deleted_at | timestamp | 是 | - |  |

## broadcast_events

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| broadcast_event_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - |  |
| event_type | varchar(100) | 否 | - |  |
| channel | varchar(200) | 否 | - |  |
| payload | json | 否 | - |  |
| is_sent | tinyint(1) | 否 | 0 |  |
| error_message | text | 是 | - |  |
| sent_at | timestamp | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## consents

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| consent_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - |  |
| user_id | bigint unsigned | 是 | - |  |
| type | varchar(50) | 否 | - |  |
| version | varchar(50) | 否 | 1.0 |  |
| is_granted | tinyint(1) | 否 | 0 |  |
| ip_address | varchar(45) | 是 | - |  |
| user_agent | varchar(500) | 是 | - |  |
| granted_at | timestamp | 是 | - |  |
| revoked_at | timestamp | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## commerce_order_items

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| item_id | bigint unsigned | 否 | - | 订单项ID（全局ID） |
| order_id | bigint unsigned | 否 | - | 所属订单 |
| sku_id | bigint unsigned | 否 | - | SKU 引用 |
| qty | int | 否 | 1 | 数量 |
| unit_price | decimal(12,2) | 否 | 0 | 单价 |
| fulfill_status | varchar(20) | 否 | pending | 履约状态: pending/fulfilled/failed/revoked |
| fulfill_at | timestamp | 是 | - | 履约时间 |
| retry_count | int | 否 | 0 | 重试次数 |
| fail_reason | varchar(500) | 是 | - | 失败原因 |
| payload_snapshot | json | 是 | - | 下单时 SKU payload 快照 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## commerce_orders

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| order_id | bigint unsigned | 否 | - | 订单ID（全局ID） |
| order_no | varchar(64) | 否 | - | 订单号 |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| amount | decimal(12,2) | 否 | 0 | 订单金额 |
| status | varchar(20) | 否 | pending | 状态: pending/paid/fulfilled/partial_failed/cancelled/refunded |
| payment_order_id | bigint unsigned | 是 | - | 关联支付单（PaymentOrder，1:1） |
| paid_at | timestamp | 是 | - | 支付时间 |
| operator_id | bigint unsigned | 是 | - | 下单 Operator |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## commerce_skus

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| sku_id | bigint unsigned | 否 | - | SKU ID（全局ID） |
| name | varchar(120) | 否 | - | 商品名称 |
| type | varchar(30) | 否 | - | 类型: plan/module/credit_pack/content_pack/mall_supply |
| role | varchar(20) | 否 | consumer | 第一级分类: consumer/supply |
| lifecycle | varchar(20) | 否 | one_time | 生命周期: subscription/one_time/consumable/grant |
| fulfill_handler | varchar(60) | 否 | - | 履约 Handler 标识 |
| price | decimal(12,2) | 否 | 0 | 售价 |
| billing_cycle | varchar(20) | 是 | - | 计费周期: monthly/yearly（订阅类） |
| payload | json | 是 | - | 差异化参数（模块名/积分面额/套餐ID等） |
| refundable | tinyint(1) | 否 | 0 | 是否可退款（积分包恒 false） |
| status | varchar(20) | 否 | draft | 状态: draft/active/retired |
| sort_order | int | 否 | 0 | 排序 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## channels

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| channel_id | bigint unsigned | 否 | - | 频道ID（全局ID） |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| type | varchar(50) | 否 | - | 频道类型: wechat_work, telegram, wechat_official, sms |
| name | varchar(200) | 是 | - | 频道显示名称 |
| app_id | varchar(200) | 是 | - | 应用ID / CorpID / Bot Username |
| app_secret | text | 是 | - | 应用密钥（加密存储） |
| agent_id | varchar(100) | 是 | - | 企微 AgentID 等 |
| callback_token | varchar(200) | 是 | - | 回调验证 Token |
| encoding_aes_key | varchar(200) | 是 | - | 消息加解密密钥 |
| status | varchar(20) | 否 | active | 状态: active / inactive / error |
| metadata | json | 是 | - | 扩展配置（webhook_url, proxy 等） |
| last_connected_at | timestamp | 是 | - | 最后连接时间 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## conversation_sessions

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| session_id | bigint unsigned | 否 | - | 会话会话 ID（IdGenerator 全局ID） |
| conversation_id | bigint unsigned | 否 | - | 会话 ID |
| user_id | bigint unsigned | 否 | - | 用户 ID |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| status | varchar(20) | 否 | active | 会话状态: active/idle/disconnected |
| connected_at | timestamp | 是 | - | 连接时间 |
| last_active_at | timestamp | 是 | - | 最后活跃时间 |
| metadata | json | 是 | - | 元数据 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## conversation_tags

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| conversation_tag_id | bigint unsigned | 否 | - | 标签 ID（IdGenerator 全局ID） |
| conversation_id | bigint unsigned | 否 | - | 会话 ID |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| tag | varchar(50) | 否 | - | 标签名称 |
| metadata | json | 是 | - | 元数据 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## conversations

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| conversation_id | bigint unsigned | 否 | - | 会话 ID（IdGenerator 全局ID） |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| created_by | bigint unsigned | 是 | - | 创建者用户ID |
| type | varchar(20) | 否 | support | 会话类型: support/group/direct |
| status | varchar(20) | 否 | active | 会话状态: active/closed/archived |
| title | varchar(255) | 是 | - | 会话标题 |
| channel | varchar(20) | 否 | web | 会话渠道 |
| agent_id | bigint unsigned | 是 | - | 分配的 Agent ID |
| last_message_at | timestamp | 是 | - | 最后消息时间 |
| message_count | int | 否 | 0 | 消息计数 |
| summary | text | 是 | - | 会话摘要 |
| summary_updated_at | timestamp | 是 | - | 摘要更新时间 |
| metadata | json | 是 | - | 元数据 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## cost_allocations

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| cost_allocation_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - |  |
| cost_type | varchar(30) | 否 | - |  |
| cost_subtype | varchar(50) | 是 | - |  |
| amount | decimal(14,4) | 否 | 0.0000 |  |
| currency | varchar(10) | 否 | CNY |  |
| period | varchar(7) | 否 | - |  |
| allocation_basis | varchar(100) | 是 | - |  |
| allocation_value | decimal(14,4) | 是 | - |  |
| metadata | json | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## coupon_distributions

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| distribution_id | bigint unsigned | 否 | - |  |
| coupon_id | bigint unsigned | 否 | - | 发放的优惠券 |
| template_id | bigint unsigned | 是 | - | 发放模板 |
| tenant_id | bigint unsigned | 是 | - | 接收租户 |
| user_id | bigint unsigned | 是 | - | 接收用户 |
| distribution_type | varchar(30) | 否 | batch | 发放类型: batch/split/invite |
| source_user_id | bigint unsigned | 是 | - | 裂变来源用户 |
| batch_id | varchar(64) | 是 | - | 批次ID |
| metadata | json | 是 | - | 附加元数据 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## coupon_rules

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| rule_id | bigint unsigned | 否 | - |  |
| coupon_id | bigint unsigned | 否 | - | 关联优惠券 |
| rule_type | varchar(50) | 否 | - | 规则类型: stackable/category_limit/tiered_threshold |
| rule_config | json | 否 | - | 规则配置 |
| priority | smallint unsigned | 否 | 0 | 优先级 |
| is_active | tinyint(1) | 否 | 1 | 是否启用 |
| description | varchar(255) | 是 | - | 规则描述 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## coupon_templates

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| template_id | bigint unsigned | 否 | - |  |
| name | varchar(255) | 否 | - | 模板名称 |
| description | varchar(255) | 是 | - | 模板描述 |
| type | varchar(20) | 否 | fixed | 类型: fixed/percentage |
| value | decimal(12,2) | 否 | 0.00 | 折扣值 |
| currency | varchar(8) | 是 | - | 币种 |
| min_amount | decimal(12,2) | 是 | - | 最低消费金额 |
| max_discount | decimal(12,2) | 是 | - | 百分比折扣上限 |
| applies_to | varchar(20) | 否 | subscription | 适用范围 |
| subscription_plan_id | bigint unsigned | 是 | - | 限定订阅计划 |
| duration_months | smallint unsigned | 是 | - | 订阅抵扣持续月数 |
| max_uses | int unsigned | 是 | - | 最大使用次数 |
| max_uses_per_tenant | smallint unsigned | 否 | 1 | 每租户最大使用次数 |
| valid_days | smallint unsigned | 否 | 30 | 有效期天数 |
| is_active | tinyint(1) | 否 | 1 | 是否启用 |
| metadata | json | 是 | - | 附加元数据 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## coupon_usages

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| coupon_usage_id | bigint unsigned | 否 | - |  |
| coupon_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - |  |
| user_id | bigint unsigned | 是 | - | 兑换用户 |
| invoice_id | bigint unsigned | 是 | - | 关联发票 |
| subscription_plan_id | bigint unsigned | 是 | - | 关联订阅计划 |
| discount_amount | decimal(12,2) | 否 | 0.00 | 实际抵扣金额 |
| currency | varchar(8) | 是 | - | 币种 |
| metadata | json | 是 | - | 附加元数据 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## coupons

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| coupon_id | bigint unsigned | 否 | - |  |
| code | varchar(64) | 否 | - | 优惠券码 |
| description | varchar(255) | 是 | - | 描述 |
| type | varchar(20) | 否 | fixed | 类型: fixed=固定金额 percentage=百分比 |
| value | decimal(12,2) | 否 | 0.00 | 折扣值: 固定金额或百分比(0-100) |
| currency | varchar(8) | 是 | - | 币种，固定金额时使用 |
| min_amount | decimal(12,2) | 是 | - | 最低消费金额 |
| max_discount | decimal(12,2) | 是 | - | 百分比折扣上限 |
| applies_to | varchar(20) | 否 | subscription | 适用范围: subscription/invoice/all |
| subscription_plan_id | bigint unsigned | 是 | - | 限定订阅计划 |
| duration_months | smallint unsigned | 是 | - | 订阅抵扣持续月数 |
| max_uses | int unsigned | 是 | - | 最大使用次数，null=不限 |
| max_uses_per_tenant | smallint unsigned | 否 | 1 | 每租户最大使用次数 |
| used_count | int unsigned | 否 | 0 | 已使用次数 |
| starts_at | timestamp | 是 | - | 生效时间 |
| expires_at | timestamp | 是 | - | 过期时间 |
| is_active | tinyint(1) | 否 | 1 | 是否启用 |
| metadata | json | 是 | - | 附加元数据 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## credit_accounts

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| credit_account_id | bigint unsigned | 否 | - | 账户ID（全局ID，16位数字） |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| user_id | bigint unsigned | 是 | - | 用户ID（NULL表示租户级账户） |
| account_type | enum('enterprise','personal') | 否 | personal | 账户类型 |
| balance | bigint unsigned | 否 | 0 | 账户余额（= gift_balance + recharge_balance） |
| gift_balance | bigint unsigned | 否 | 0 | 赠送余额（优先扣减） |
| recharge_balance | bigint unsigned | 否 | 0 | 充值余额 |
| total_recharged | bigint unsigned | 否 | 0 | 累计充值 |
| total_consumed | bigint unsigned | 否 | 0 | 累计消费 |
| expires_at | timestamp | 是 | - | 账户积分过期时间 |
| expired_total | int | 否 | 0 | 累计过期积分 |
| last_warning_at | timestamp | 是 | - | 上次低余额预警时间 |
| auto_recharge_enabled | tinyint(1) | 否 | 0 | 是否启用自动充值 |
| auto_recharge_threshold | int | 否 | 100 | 自动充值触发阈值 |
| auto_recharge_amount | int | 否 | 1000 | 自动充值金额 |
| status | enum('active','frozen','closed') | 否 | active | 账户状态 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## credit_transactions

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| transaction_id | bigint unsigned | 否 | - | 交易ID（全局ID，16位数字） |
| account_id | bigint unsigned | 否 | - | 账户ID（关联credit_accounts） |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| user_id | bigint unsigned | 否 | - | 用户ID |
| type | enum('recharge','consume','refund','transfer','gift','expire') | 否 | - | 交易类型 |
| amount | bigint | 否 | - | 金额（正数=收入，负数=支出） |
| balance_after | bigint unsigned | 否 | - | 交易后余额 |
| related_type | varchar(100) | 是 | - | 关联模型类型 |
| related_id | varchar(100) | 是 | - | 关联模型ID |
| description | varchar(255) | 是 | - | 交易描述 |
| expires_at | timestamp | 是 | - | 交易积分过期时间（仅充值/赠送类型） |
| expired | tinyint(1) | 否 | 0 | 是否已过期 |
| metadata | json | 是 | - | 元数据 |
| created_at | timestamp | 是 | - |  |

## custom_reports

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| custom_report_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - |  |
| name | varchar(200) | 否 | - |  |
| description | varchar(500) | 是 | - |  |
| metrics_config | json | 是 | - |  |
| dimensions | json | 是 | - |  |
| time_range | varchar(30) | 否 | last_7_days |  |
| start_at | timestamp | 是 | - |  |
| end_at | timestamp | 是 | - |  |
| frequency | varchar(20) | 否 | daily |  |
| recipients | json | 是 | - |  |
| format | varchar(20) | 否 | csv |  |
| template | varchar(100) | 是 | - |  |
| status | varchar(20) | 否 | active |  |
| last_sent_at | timestamp | 是 | - |  |
| next_send_at | timestamp | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |
| deleted_at | timestamp | 是 | - |  |

## data_retention_policies

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| data_retention_policy_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - |  |
| data_type | varchar(50) | 否 | - |  |
| retention_days | int unsigned | 否 | 365 |  |
| auto_cleanup | tinyint(1) | 否 | 0 |  |
| cleanup_strategy | varchar(20) | 否 | delete |  |
| is_exempt | tinyint(1) | 否 | 0 |  |
| description | varchar(255) | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## dead_letters

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| dead_letter_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - |  |
| event_type | varchar(100) | 否 | - |  |
| subscription_id | bigint unsigned | 是 | - |  |
| original_data | json | 是 | - |  |
| failure_reason | text | 是 | - |  |
| retry_count | int unsigned | 否 | 0 |  |
| status | varchar(20) | 否 | failed |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## email_verification_tokens

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | bigint unsigned | 否 | - |  |
| email | varchar(255) | 否 | - |  |
| token | varchar(255) | 否 | - |  |
| created_at | timestamp | 是 | - |  |

## entity_memories

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| memory_id | bigint unsigned | 否 | - | 记忆ID |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| entity_type | varchar(100) | 否 | - | 实体类型 |
| entity_id | bigint unsigned | 否 | - | 实体ID |
| key | varchar(200) | 否 | - | 记忆键 |
| value | json | 是 | - | 记忆值(JSON) |
| weight | float | 否 | 1 | 权重 |
| last_accessed_at | timestamp | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## event_subscriptions

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| event_subscription_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - |  |
| event_type | varchar(100) | 否 | - |  |
| subscription_type | varchar(20) | 否 | internal |  |
| handler | varchar(500) | 否 | - |  |
| secret | varchar(128) | 是 | - |  |
| is_active | tinyint(1) | 否 | 1 |  |
| description | varchar(255) | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |
| deleted_at | timestamp | 是 | - |  |

## export_tasks

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - |  |
| user_id | bigint unsigned | 是 | - |  |
| job_class | varchar(255) | 否 | - |  |
| payload | json | 是 | - |  |
| status | varchar(20) | 否 | pending |  |
| file_path | varchar(500) | 是 | - |  |
| error | tinyint(1) | 否 | 0 |  |
| completed_at | timestamp | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## feature_flags

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| feature_flag_id | bigint unsigned | 否 | - |  |
| name | varchar(100) | 否 | - |  |
| description | varchar(255) | 是 | - |  |
| scope | varchar(20) | 否 | global |  |
| conditions | json | 是 | - |  |
| dependencies | json | 是 | - |  |
| rollout_percentage | tinyint unsigned | 否 | 0 |  |
| status | varchar(20) | 否 | inactive |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |
| deleted_at | timestamp | 是 | - |  |

## file_uploads

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| file_upload_id | bigint unsigned | 否 | - | 文件ID（全局ID） |
| tenant_id | bigint unsigned | 是 | - |  |
| user_id | bigint unsigned | 是 | - |  |
| disk | varchar(20) | 否 | local | 存储磁盘: local/s3/oss |
| path | varchar(500) | 否 | - | 存储路径 |
| filename | varchar(255) | 否 | - | 原始文件名 |
| mime_type | varchar(100) | 是 | - |  |
| size | bigint unsigned | 否 | 0 | 文件大小(字节) |
| hash | varchar(64) | 是 | - | 文件哈希，用于去重 |
| category | varchar(50) | 否 | general | 文件分类 |
| is_public | tinyint(1) | 否 | 0 | 是否公开可访问 |
| metadata | json | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |
| deleted_at | timestamp | 是 | - |  |

## financial_records

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| financial_record_id | bigint unsigned | 否 | - | 财务记录ID（全局ID，16位数字） |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| type | enum('subscription','recharge','commission','refund') | 否 | - | 交易类型 |
| amount | bigint unsigned | 否 | - | 金额（分） |
| status | enum('pending','completed','failed','refunded') | 否 | pending | 状态 |
| payment_method | varchar(50) | 是 | - | 支付方式 |
| payment_order_no | varchar(100) | 是 | - | 支付订单号 |
| paid_at | timestamp | 是 | - | 支付时间 |
| metadata | json | 是 | - | 元数据 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## form_fields

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| field_id | bigint unsigned | 否 | - |  |
| form_id | bigint unsigned | 否 | - | 所属表单 |
| field_key | varchar(64) | 否 | - | 字段标识 |
| field_type | varchar(32) | 否 | text | 字段类型 |
| label | varchar(255) | 否 | - | 字段标签 |
| placeholder | varchar(255) | 是 | - | 占位提示 |
| default_value | text | 是 | - | 默认值 |
| options | json | 是 | - | 选项列表 |
| is_required | tinyint(1) | 否 | 0 | 是否必填 |
| sort_order | smallint unsigned | 否 | 0 | 排序 |
| validation_rules | json | 是 | - | 校验规则 |
| metadata | json | 是 | - | 附加元数据 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## form_submission_data

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| submission_data_id | bigint unsigned | 否 | - | 提交数据ID |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| submission_id | bigint unsigned | 否 | - | 提交ID |
| field_id | bigint unsigned | 否 | - | 字段ID |
| value | text | 是 | - | 字段值 |
| created_at | timestamp | 是 | - | 创建时间 |

## form_submissions

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| submission_id | bigint unsigned | 否 | - |  |
| form_id | bigint unsigned | 否 | - | 所属表单 |
| tenant_id | bigint unsigned | 是 | - | 租户 ID |
| user_id | bigint unsigned | 是 | - | 提交用户 |
| data | json | 否 | - | 提交数据 |
| status | varchar(20) | 否 | pending | 状态: pending/approved/rejected |
| ip_address | varchar(45) | 是 | - | 提交 IP |
| user_agent | varchar(255) | 是 | - | User Agent |
| submitted_at | timestamp | 是 | - | 提交时间 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## forms

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| form_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - | 租户 ID |
| title | varchar(255) | 否 | - | 表单标题 |
| slug | varchar(100) | 是 | - | 表单标识 |
| description | varchar(255) | 是 | - | 表单描述 |
| status | varchar(20) | 否 | draft | 状态: draft/published/closed |
| submit_limit | int unsigned | 否 | 0 | 提交上限，0=不限 |
| submit_count | int unsigned | 否 | 0 | 提交次数 |
| start_at | timestamp | 是 | - | 开始时间 |
| end_at | timestamp | 是 | - | 结束时间 |
| submit_text | varchar(50) | 否 | 提交 | 提交按钮文字 |
| success_message | varchar(255) | 否 | 提交成功 | 提交成功提示 |
| is_public | tinyint(1) | 否 | 0 | 是否公开 |
| require_login | tinyint(1) | 否 | 0 | 是否需要登录 |
| metadata | json | 是 | - | 附加元数据 |
| settings | json | 是 | - | 表单设置 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## in_app_notifications

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| in_app_notification_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - |  |
| user_id | bigint unsigned | 否 | - |  |
| type | varchar(30) | 否 | system |  |
| title | varchar(200) | 否 | - |  |
| body | text | 是 | - |  |
| link | varchar(500) | 是 | - |  |
| is_read | tinyint(1) | 否 | 0 |  |
| read_at | timestamp | 是 | - |  |
| metadata | json | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## invoice_items

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - |  |
| invoice_id | bigint unsigned | 否 | - |  |
| description | varchar(255) | 否 | - |  |
| quantity | decimal(8,2) | 否 | - |  |
| unit_price | decimal(12,2) | 否 | - |  |
| amount | decimal(12,2) | 否 | - |  |
| tax_rate | decimal(5,4) | 否 | - |  |
| tax_amount | decimal(12,2) | 否 | - |  |
| related_type | varchar(255) | 是 | - |  |
| related_id | bigint unsigned | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## invoices

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - |  |
| invoice_number | varchar(255) | 否 | - |  |
| subtotal | decimal(12,2) | 否 | - |  |
| tax_amount | decimal(12,2) | 否 | - |  |
| total | decimal(12,2) | 否 | - |  |
| currency | varchar(3) | 否 | - |  |
| status | varchar(20) | 否 | draft |  |
| issued_at | datetime | 是 | - |  |
| due_date | date | 是 | - |  |
| subscription_id | bigint unsigned | 是 | - |  |
| payment_order_id | bigint unsigned | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## ip_whitelists

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| ip_whitelist_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - |  |
| ip_value | varchar(100) | 否 | - |  |
| description | varchar(255) | 是 | - |  |
| scope | varchar(20) | 否 | all |  |
| is_enabled | tinyint(1) | 否 | 1 |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## lotteries

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| lottery_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - | 租户 ID |
| title | varchar(255) | 否 | - | 抽奖标题 |
| description | varchar(255) | 是 | - | 抽奖描述 |
| status | varchar(20) | 否 | draft | 状态: draft/active/ended |
| start_at | timestamp | 否 | - | 开始时间 |
| end_at | timestamp | 否 | - | 结束时间 |
| daily_limit | int unsigned | 否 | 0 | 每日总参与次数上限，0=不限 |
| total_limit | int unsigned | 否 | 0 | 总参与次数上限，0=不限 |
| daily_limit_per_user | int unsigned | 否 | 0 | 每用户每日限制 |
| total_limit_per_user | int unsigned | 否 | 0 | 每用户总限制 |
| anti_cheat_ip | tinyint(1) | 否 | 1 | 是否启用 IP 防刷 |
| no_prize_probability | int unsigned | 否 | 0 | 未中奖概率权重(千分比) |
| prize_show_count | smallint unsigned | 否 | 8 | 奖品展示数量 |
| total_draws | int unsigned | 否 | 0 | 总抽奖次数 |
| total_wins | int unsigned | 否 | 0 | 总中奖次数 |
| metadata | json | 是 | - | 附加元数据 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## lottery_pools

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | bigint unsigned | 否 | - |  |
| prize_config | json | 是 | - |  |
| probability_rules | json | 是 | - |  |
| anti_abuse_config | json | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## lottery_prizes

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| prize_id | bigint unsigned | 否 | - |  |
| lottery_id | bigint unsigned | 否 | - | 所属抽奖 |
| name | varchar(255) | 否 | - | 奖品名称 |
| image | varchar(255) | 是 | - | 奖品图片 |
| prize_type | varchar(20) | 否 | physical | 奖品类型: physical/virtual/coupon |
| probability | int unsigned | 否 | 0 | 中奖概率权重(千分比) |
| stock | int unsigned | 否 | 0 | 库存 |
| sort_order | smallint unsigned | 否 | 0 | 排序 |
| metadata | json | 是 | - | 附加元数据 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## lottery_records

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| record_id | bigint unsigned | 否 | - |  |
| lottery_id | bigint unsigned | 否 | - | 所属抽奖 |
| prize_id | bigint unsigned | 是 | - | 中奖奖品 |
| user_id | bigint unsigned | 否 | - | 参与用户 |
| tenant_id | bigint unsigned | 否 | - | 租户 ID |
| is_winner | tinyint(1) | 否 | 0 | 是否中奖 |
| prize_name | varchar(255) | 否 | 谢谢参与 | 奖品名称 |
| ip_address | varchar(45) | 是 | - | IP 地址 |
| user_agent | varchar(255) | 是 | - | User Agent |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## mail_templates

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| template_id | bigint unsigned | 否 | - | 模板ID（全局ID，16位数字） |
| tenant_id | bigint unsigned | 是 | - | 租户ID，NULL表示系统默认模板 |
| type | varchar(50) | 否 | - | 类型: billing/notification/welcome/reset |
| name_key | varchar(50) | 是 | - | 模板固定标识符，用于幂等匹配，不受 locale 影响 |
| name | varchar(255) | 否 | - | 模板名称 |
| subject | varchar(255) | 否 | - | 邮件主题 |
| html_body | longtext | 否 | - | HTML 正文 |
| text_body | text | 是 | - | 纯文本正文 |
| variables | json | 是 | - | 变量定义（JSON） |
| status | varchar(20) | 否 | activated | 状态: activated/disabled |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |
| deleted_at | timestamp | 是 | - |  |

## mcp_client_tokens

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| mcp_client_token_id | bigint unsigned | 否 | - |  |
| mcp_client_id | bigint unsigned | 否 | - | 关联客户端 |
| tenant_id | bigint unsigned | 是 | - | 关联租户 |
| token | varchar(64) | 否 | - | SHA256 哈希后的 Token |
| token_plain | varchar(128) | 是 | - | 明文 Token（仅创建时返回） |
| abilities | json | 是 | - | 权限列表 |
| expires_at | timestamp | 是 | - | 过期时间 |
| is_active | tinyint(1) | 否 | 1 | 是否启用 |
| last_used_at | timestamp | 是 | - | 最后使用时间 |
| last_used_count | int unsigned | 否 | 0 | 累计使用次数 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## mcp_clients

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| mcp_client_id | bigint unsigned | 否 | - |  |
| slug | varchar(64) | 否 | - | 客户端标识 |
| name | varchar(255) | 否 | - | 客户端名称 |
| output_format | varchar(32) | 否 | json_config | 输出格式: markdown_skill/json_config |
| description | varchar(255) | 是 | - | 描述 |
| is_enabled | tinyint(1) | 否 | 1 | 是否启用 |
| config | json | 是 | - | 客户端配置 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## mcp_tool_access_logs

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| log_id | bigint unsigned | 否 | - |  |
| mcp_client_id | bigint unsigned | 是 | - | 调用客户端 |
| mcp_client_token_id | bigint unsigned | 是 | - | 使用的 Token |
| tenant_id | bigint unsigned | 是 | - | 租户 ID |
| tool_name | varchar(128) | 否 | - | 工具名称 |
| arguments | json | 是 | - | 调用参数 |
| result | json | 是 | - | 返回结果 |
| status | varchar(20) | 否 | success | 状态: success/error |
| duration_ms | int unsigned | 是 | - | 执行时长(毫秒) |
| ip_address | varchar(45) | 是 | - | 请求 IP |
| user_agent | varchar(255) | 是 | - | User Agent |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## mentions

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| mention_id | bigint unsigned | 否 | - | 提及 ID（IdGenerator 全局ID） |
| message_id | bigint unsigned | 否 | - | 消息 ID |
| user_id | bigint unsigned | 否 | - | 被提及用户 ID |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| is_notified | tinyint(1) | 否 | 0 | 是否已通知 |
| metadata | json | 是 | - | 元数据 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## messages

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| message_id | bigint unsigned | 否 | - | 消息 ID（IdGenerator 全局ID） |
| conversation_id | bigint unsigned | 否 | - | 会话 ID |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| sender_id | bigint unsigned | 是 | - | 发送者用户ID |
| sender_type | varchar(20) | 否 | user | 发送者类型: user/agent/system |
| type | varchar(20) | 否 | text | 消息类型: text/image/file/system |
| content | text | 是 | - | 消息内容 |
| attachments | json | 是 | - | 附件列表 JSON |
| metadata | json | 是 | - | 元数据 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## metrics_snapshots

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| metrics_snapshot_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - |  |
| metric_name | varchar(100) | 否 | - |  |
| metric_value | double | 否 | 0 |  |
| dimension_type | varchar(30) | 是 | - |  |
| dimension_value | varchar(200) | 是 | - |  |
| granularity | varchar(10) | 否 | minute |  |
| aggregated | tinyint(1) | 否 | 0 |  |
| sampled_at | timestamp | 否 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## mfa_devices

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| mfa_device_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - |  |
| user_id | bigint unsigned | 否 | - |  |
| type | varchar(20) | 否 | - |  |
| secret | text | 是 | - |  |
| label | varchar(100) | 是 | - |  |
| is_primary | tinyint(1) | 否 | 0 |  |
| is_verified | tinyint(1) | 否 | 0 |  |
| last_used_at | timestamp | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## mfa_recovery_codes

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| recovery_code_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - |  |
| user_id | bigint unsigned | 否 | - |  |
| code | varchar(255) | 否 | - |  |
| is_used | tinyint(1) | 否 | 0 |  |
| used_at | timestamp | 是 | - |  |
| created_at | timestamp | 是 | - |  |

## module_entitlements

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| entitlement_id | bigint unsigned | 否 | - | 权益ID（全局ID） |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| module_name | varchar(60) | 否 | - | 模块标识 |
| source | varchar(20) | 否 | purchase | 来源: plan/purchase/system |
| source_order_id | bigint unsigned | 是 | - | 来源订单 |
| valid_from | timestamp | 是 | - | 生效时间 |
| valid_until | timestamp | 是 | - | 失效时间（NULL=永久买断） |
| status | varchar(20) | 否 | active | 状态: active/expired/revoked |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## modules

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| name | varchar(50) | 否 | - |  |
| version | varchar(20) | 否 | 0.0.0 |  |
| status | enum('installed','enabled','disabled') | 否 | installed |  |
| config | json | 是 | - |  |
| installed_at | timestamp | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## notification_preferences

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| notification_preference_id | bigint unsigned | 否 | - | 偏好ID（全局ID） |
| user_id | bigint unsigned | 否 | - |  |
| channel | varchar(30) | 否 | - | 通知通道: database, mail, broadcast |
| type | varchar(100) | 是 | - | 通知类型, null=全局默认 |
| enabled | tinyint(1) | 否 | 1 | 是否启用 |
| options | json | 是 | - | 通道选项 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## notifications

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | char(36) | 否 | - |  |
| type | varchar(255) | 否 | - |  |
| notifiable_type | varchar(255) | 否 | - |  |
| notifiable_id | bigint unsigned | 否 | - |  |
| data | text | 否 | - |  |
| read_at | timestamp | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## oauth_accounts

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| oauth_account_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - |  |
| user_id | bigint unsigned | 否 | - |  |
| provider | varchar(50) | 否 | - |  |
| provider_id | varchar(100) | 否 | - |  |
| provider_email | varchar(255) | 是 | - |  |
| provider_name | varchar(255) | 是 | - |  |
| provider_avatar | varchar(500) | 是 | - |  |
| access_token | text | 是 | - |  |
| refresh_token | text | 是 | - |  |
| token_expires_at | timestamp | 是 | - |  |
| metadata | json | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## operator_tenants

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | bigint unsigned | 否 | - |  |
| operator_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - |  |
| role | varchar(50) | 否 | - |  |
| role_id | bigint unsigned | 是 | - |  |
| is_active | tinyint(1) | 否 | 1 |  |
| invited_at | timestamp | 是 | - |  |
| accepted_at | timestamp | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## operators

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| operator_id | bigint unsigned | 否 | - |  |
| email | varchar(255) | 否 | - |  |
| name | varchar(255) | 否 | - |  |
| password | varchar(255) | 是 | - |  |
| phone | varchar(20) | 是 | - |  |
| avatar | varchar(500) | 是 | - |  |
| scope | varchar(20) | 否 | - |  |
| is_active | tinyint(1) | 否 | 0 |  |
| email_verified_at | timestamp | 是 | - |  |
| last_login_at | timestamp | 是 | - |  |
| login_attempts | int | 否 | 0 |  |
| locked_until | timestamp | 是 | - |  |
| password_changed_at | timestamp | 是 | - |  |
| invite_token | varchar(100) | 是 | - |  |
| invite_expires_at | timestamp | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |
| deleted_at | timestamp | 是 | - |  |

## participants

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| participant_id | bigint unsigned | 否 | - | 参与者 ID（IdGenerator 全局ID） |
| conversation_id | bigint unsigned | 否 | - | 会话 ID |
| user_id | bigint unsigned | 否 | - | 用户 ID |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| role | varchar(20) | 否 | member | 参与者角色: member/agent/admin/guest |
| is_muted | tinyint(1) | 否 | 0 | 是否静音 |
| left_at | timestamp | 是 | - | 离开时间 |
| last_read_at | timestamp | 是 | - | 最后已读时间 |
| metadata | json | 是 | - | 元数据 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## password_histories

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| password_history_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - |  |
| user_id | bigint unsigned | 否 | - |  |
| password_hash | varchar(255) | 否 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## payment_logs

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - |  |
| user_id | bigint unsigned | 是 | - |  |
| order_no | varchar(64) | 是 | - |  |
| amount | decimal(12,2) | 否 | 0.00 |  |
| status | varchar(20) | 否 | - |  |
| context | json | 是 | - |  |
| ip_address | varchar(45) | 是 | - |  |
| user_agent | varchar(500) | 是 | - |  |
| created_at | timestamp | 是 | - |  |

## payment_orders

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | bigint unsigned | 否 | - |  |
| tenant_id | bigint | 否 | - |  |
| order_no | varchar(64) | 否 | - |  |
| driver | varchar(20) | 否 | wechat |  |
| amount | decimal(10,2) | 否 | - |  |
| description | varchar(255) | 是 | - |  |
| status | varchar(20) | 否 | pending |  |
| paid_at | timestamp | 是 | - |  |
| transaction_id | varchar(255) | 是 | - |  |
| extra | json | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |
| deleted_at | timestamp | 是 | - |  |

## permissions

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| permission_id | bigint unsigned | 否 | - | 权限ID（全局ID） |
| name | varchar(100) | 否 | - | 权限标识，如 tenant.users.create |
| display_name | varchar(200) | 否 | - |  |
| group | varchar(50) | 否 | general | 权限分组 |
| description | text | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## platform_content_pack_items

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| pack_id | bigint unsigned | 否 | - | 内容包ID |
| content_id | bigint unsigned | 否 | - | 内容ID |
| sort_order | int | 否 | 0 | 排序 |

## platform_content_packs

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| pack_id | bigint unsigned | 否 | - | 内容包ID（全局ID） |
| name | varchar(200) | 否 | - | 包名 |
| description | varchar(500) | 是 | - | 描述 |
| cover_url | varchar(500) | 是 | - | 封面 |
| status | varchar(20) | 否 | draft | 状态: draft/active/retired |
| sort_order | int | 否 | 0 | 排序 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## platform_contents

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| content_id | bigint unsigned | 否 | - | 内容ID（全局ID） |
| title | varchar(200) | 否 | - | 标题 |
| type | varchar(30) | 否 | article | 类型: article/video/audio/image/file |
| body | text | 是 | - | 正文（富文本/纯文本） |
| file_url | varchar(500) | 是 | - | 媒体文件地址 |
| cover_url | varchar(500) | 是 | - | 封面 |
| tags | json | 是 | - | 标签 |
| status | varchar(20) | 否 | draft | 状态: draft/published/retired |
| sort_order | int | 否 | 0 | 排序 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## plugin_dependencies

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | bigint unsigned | 否 | - |  |
| plugin_id | bigint unsigned | 否 | - |  |
| dependency_name | varchar(200) | 否 | - |  |
| version_constraint | varchar(100) | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## plugins

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - |  |
| name | varchar(100) | 否 | - |  |
| version | varchar(30) | 是 | - |  |
| status | varchar(20) | 否 | installed |  |
| manifest | json | 是 | - |  |
| config | json | 是 | - |  |
| installed_at | timestamp | 是 | - |  |
| enabled_at | timestamp | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## rate_limit_rules

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - |  |
| scope | varchar(20) | 否 | user |  |
| pattern | varchar(200) | 是 | - |  |
| max_attempts | int unsigned | 否 | 60 |  |
| decay_sec | int unsigned | 否 | 60 |  |
| strategy | varchar(30) | 否 | fixed |  |
| enabled | tinyint(1) | 否 | 1 |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## reactions

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| reaction_id | bigint unsigned | 否 | - | 回应 ID（IdGenerator 全局ID） |
| message_id | bigint unsigned | 否 | - | 消息 ID |
| user_id | bigint unsigned | 否 | - | 用户 ID |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| emoji | varchar(20) | 否 | - | 表情符号 |
| metadata | json | 是 | - | 元数据 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## read_states

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| read_state_id | bigint unsigned | 否 | - | 已读状态 ID（IdGenerator 全局ID） |
| conversation_id | bigint unsigned | 否 | - | 会话 ID |
| user_id | bigint unsigned | 否 | - | 用户 ID |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| last_read_message_id | bigint unsigned | 是 | - | 最后已读消息 ID |
| unread_count | int | 否 | 0 | 未读消息数 |
| last_read_at | timestamp | 是 | - | 最后已读时间 |
| metadata | json | 是 | - | 元数据 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## role_permissions

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | bigint unsigned | 否 | - |  |
| role_id | bigint unsigned | 否 | - |  |
| permission_id | bigint unsigned | 否 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## roles

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| role_id | bigint unsigned | 否 | - | 角色ID（全局ID） |
| tenant_id | bigint unsigned | 是 | - | null=系统级角色 |
| name | varchar(50) | 否 | - | 角色标识 |
| display_name | varchar(200) | 否 | - |  |
| description | text | 是 | - |  |
| is_system | tinyint(1) | 否 | 0 | 系统内置角色不可删除 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## sandbox_environments

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| sandbox_environment_id | bigint unsigned | 否 | - |  |
| developer_id | bigint unsigned | 否 | - |  |
| sandbox_tenant_id | bigint unsigned | 否 | - |  |
| api_key | varchar(128) | 否 | - |  |
| status | varchar(20) | 否 | active |  |
| expires_at | timestamp | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## sla_events

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| sla_event_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - |  |
| event_type | varchar(20) | 否 | - |  |
| severity | varchar(20) | 否 | warning |  |
| affected_scope | varchar(100) | 否 | global |  |
| affected_count | int unsigned | 否 | 0 |  |
| started_at | timestamp | 否 | - |  |
| ended_at | timestamp | 是 | - |  |
| duration_sec | int unsigned | 否 | 0 |  |
| status | varchar(20) | 否 | active |  |
| root_cause | text | 是 | - |  |
| resolution_notes | text | 是 | - |  |
| metadata | json | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## sms_batch_tasks

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| task_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - | 租户 ID |
| template_id | bigint unsigned | 是 | - | 短信模板 |
| name | varchar(255) | 否 | - | 任务名称 |
| status | varchar(20) | 否 | pending | 状态: pending/sending/completed/failed |
| total_count | int unsigned | 否 | 0 | 总数 |
| sent_count | int unsigned | 否 | 0 | 已发送 |
| success_count | int unsigned | 否 | 0 | 成功数 |
| fail_count | int unsigned | 否 | 0 | 失败数 |
| scheduled_at | timestamp | 是 | - | 计划发送时间 |
| started_at | timestamp | 是 | - | 开始发送时间 |
| completed_at | timestamp | 是 | - | 完成时间 |
| metadata | json | 是 | - | 附加元数据 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## sms_send_logs

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| log_id | bigint unsigned | 否 | - |  |
| task_id | bigint unsigned | 是 | - | 批次任务 |
| tenant_id | bigint unsigned | 是 | - | 租户 ID |
| phone | varchar(20) | 否 | - | 手机号 |
| content | text | 否 | - | 短信内容 |
| template_id | bigint unsigned | 是 | - | 使用的模板 |
| status | varchar(20) | 否 | pending | 状态: pending/sent/delivered/failed |
| provider | varchar(20) | 否 | - | 发送渠道 |
| provider_response | json | 是 | - | 渠道响应 |
| error_message | varchar(255) | 是 | - | 错误信息 |
| sent_at | timestamp | 是 | - | 发送时间 |
| delivered_at | timestamp | 是 | - | 送达时间 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## sms_templates

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| template_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - | 租户 ID |
| name | varchar(255) | 否 | - | 模板名称 |
| code | varchar(64) | 否 | - | 模板编码 |
| content | text | 否 | - | 模板内容 |
| type | varchar(20) | 否 | marketing | 类型: marketing/notification/verification |
| sign_name | varchar(20) | 是 | - | 短信签名 |
| params | json | 是 | - | 模板参数定义 |
| status | varchar(20) | 否 | active | 状态: active/inactive/auditing |
| metadata | json | 是 | - | 附加元数据 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## sso_providers

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| sso_provider_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - |  |
| type | varchar(20) | 否 | - |  |
| name | varchar(100) | 否 | - |  |
| display_name | varchar(200) | 是 | - |  |
| entity_id | varchar(500) | 是 | - |  |
| metadata_url | varchar(500) | 是 | - |  |
| certificate | text | 是 | - |  |
| sso_url | varchar(500) | 是 | - |  |
| slo_url | varchar(500) | 是 | - |  |
| client_id | varchar(200) | 是 | - |  |
| client_secret | text | 是 | - |  |
| authorize_url | varchar(500) | 是 | - |  |
| token_url | varchar(500) | 是 | - |  |
| userinfo_url | varchar(500) | 是 | - |  |
| scope | varchar(200) | 否 | openid profile email |  |
| attribute_mapping | json | 是 | - |  |
| status | varchar(20) | 否 | active |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## structured_logs

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - |  |
| user_id | bigint unsigned | 是 | - |  |
| category | varchar(30) | 否 | - |  |
| action | varchar(100) | 否 | - |  |
| context | json | 是 | - |  |
| ip_address | varchar(45) | 是 | - |  |
| user_agent | varchar(500) | 是 | - |  |
| created_at | timestamp | 是 | - |  |

## subscription_histories

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| subscription_history_id | bigint unsigned | 否 | - | 历史ID（全局ID） |
| tenant_id | bigint unsigned | 否 | - |  |
| plan_id | bigint unsigned | 是 | - |  |
| action | varchar(30) | 否 | - | subscribe, cancel, change, trial, renew, downgrade, upgrade |
| from_plan | varchar(50) | 是 | - | 变更前计划 |
| to_plan | varchar(50) | 是 | - | 变更后计划 |
| billing_cycle | varchar(20) | 是 | - | monthly, yearly |
| amount | decimal(10,2) | 否 | 0.00 | 操作金额 |
| proration_amount | decimal(10,2) | 否 | 0.00 | 按比例退补金额 |
| starts_at | timestamp | 是 | - |  |
| expires_at | timestamp | 是 | - |  |
| notes | text | 是 | - |  |
| metadata | json | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## subscription_plans

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| subscription_plan_id | bigint unsigned | 否 | - | 计划ID（全局ID） |
| name | varchar(50) | 否 | - | 计划标识: free/basic/pro/enterprise |
| display_name | varchar(200) | 否 | - |  |
| description | text | 是 | - |  |
| price_monthly | int | 否 | 0 | 月价（分） |
| price_yearly | int | 否 | 0 | 年价（分） |
| trial_days | smallint unsigned | 否 | 0 | 试用期天数，0=无试用 |
| features | json | 是 | - | 功能特性列表 |
| limits | json | 是 | - | 资源限制: max_users/max_storage等 |
| is_active | tinyint(1) | 否 | 1 |  |
| sort_order | smallint unsigned | 否 | 0 |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## supply_grants

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| grant_id | bigint unsigned | 否 | - | 授权ID（全局ID） |
| tenant_id | bigint unsigned | 否 | - | 获授权租户 |
| sku_id | bigint unsigned | 否 | - | 供给 SKU 引用 |
| source_order_id | bigint unsigned | 是 | - | 来源订单 |
| status | varchar(20) | 否 | active | 状态: active/suspended/expired/revoked |
| valid_from | timestamp | 是 | - | 生效时间 |
| valid_until | timestamp | 是 | - | 失效时间（NULL=永久） |
| settlement | json | 是 | - | 结算参数（供货价/分成比例/模式） |
| instance_payload | json | 是 | - | 履约产物引用（content_id / points_product_id 等） |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## system_settings

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| setting_id | bigint unsigned | 否 | - | 配置ID（全局ID，16位数字） |
| group | varchar(50) | 否 | - | 配置组（dify/system/mail/credit） |
| key | varchar(100) | 否 | - | 配置键 |
| value | text | 是 | - | 配置值（支持JSON） |
| is_encrypted | tinyint(1) | 否 | 0 | 是否加密存储 |
| description | varchar(255) | 是 | - | 配置说明 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## tax_rules

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - |  |
| region_code | varchar(10) | 否 | - |  |
| tax_rate | decimal(5,4) | 否 | - |  |
| tax_name | varchar(255) | 否 | - |  |
| effective_date | date | 否 | - |  |
| expiry_date | date | 是 | - |  |
| is_default | tinyint(1) | 否 | 0 |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## tenant_hierarchies

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| tenant_hierarchy_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - | 父租户 ID（隔离作用域依据） |
| child_tenant_id | bigint unsigned | 否 | - | 子租户 ID |
| relation_type | varchar(30) | 否 | subsidiary | 关系类型: subsidiary/branch/division |
| permission_scope | json | 是 | - | 权限范围：资源共享、跨租户访问授权、计费聚合等 |
| is_active | tinyint(1) | 否 | 1 | 关系是否有效 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |
| deleted_at | timestamp | 是 | - |  |

## tenant_keys

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| tenant_key_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - |  |
| encrypted_key | text | 否 | - | 经系统主密钥加密后的租户 AES 密钥 |
| key_type | varchar(20) | 否 | system | system / byok |
| status | varchar(20) | 否 | active | active / rotating / retired |
| previous_key_id | bigint unsigned | 是 | - | 轮换前的上一把密钥 ID |
| rotated_at | timestamp | 是 | - | 轮换时间 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |
| deleted_at | timestamp | 是 | - |  |

## tenant_memories

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| memory_id | bigint unsigned | 否 | - | 记忆ID |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| type | varchar(50) | 否 | - | 类型: preference/rule/decision |
| key | varchar(200) | 否 | - | 记忆键 |
| value | json | 是 | - | 记忆值(JSON) |
| weight | float | 否 | 1 | 权重 |
| last_accessed_at | timestamp | 是 | - | 最后访问时间 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## tenant_modules

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - |  |
| module_name | varchar(50) | 否 | - |  |
| status | enum('enabled','disabled') | 否 | enabled |  |
| config | json | 是 | - |  |
| enabled_at | timestamp | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## tenant_settings

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| setting_id | bigint unsigned | 否 | - | 配置ID（全局ID，16位数字） |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| group | varchar(50) | 否 | - | 配置组（oauth/mail/info） |
| key | varchar(100) | 否 | - | 配置键 |
| value | text | 是 | - | 配置值（支持JSON） |
| is_encrypted | tinyint(1) | 否 | 0 | 是否加密存储 |
| description | varchar(255) | 是 | - | 配置说明 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## tenant_users

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| tenant_user_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - |  |
| user_id | bigint unsigned | 否 | - |  |
| role_id | bigint unsigned | 是 | - |  |
| credits | int | 否 | 0 |  |
| is_active | tinyint(1) | 否 | 1 |  |
| joined_at | timestamp | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## tenants

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| tenant_id | bigint unsigned | 否 | - |  |
| name | varchar(100) | 否 | - |  |
| slug | varchar(100) | 是 | - |  |
| domain | varchar(200) | 是 | - |  |
| custom_domain | varchar(200) | 是 | - |  |
| logo | varchar(500) | 是 | - |  |
| description | text | 是 | - |  |
| subscription_plan | varchar(50) | 否 | free |  |
| subscription_plan_id | bigint unsigned | 是 | - |  |
| subscription_started_at | timestamp | 是 | - |  |
| subscription_expires_at | timestamp | 是 | - |  |
| auto_renew | tinyint(1) | 否 | 0 |  |
| trial_ends_at | timestamp | 是 | - |  |
| trial_extended | tinyint(1) | 否 | 0 | 试用期是否已延长 |
| trial_notification_sent_at | timestamp | 是 | - | 试用期通知发送时间 |
| total_credits | int | 否 | 0 |  |
| used_credits | int | 否 | 0 |  |
| contact_name | varchar(50) | 是 | - |  |
| contact_email | varchar(100) | 是 | - |  |
| contact_phone | varchar(20) | 是 | - |  |
| settings | json | 是 | - |  |
| branding | json | 是 | - |  |
| is_platform_default | tinyint(1) | 否 | 0 |  |
| status | varchar(20) | 否 | active |  |
| isolation_type | varchar(20) | 否 | shared | 隔离策略: shared/database/schema |
| database_name | varchar(100) | 是 | - | 独立数据库名（database 策略） |
| schema_name | varchar(100) | 是 | - | 独立 Schema 名（schema 策略） |
| onboarding_step | smallint | 否 | 0 | 当前 onboarding 步骤 |
| onboarding_completed | tinyint(1) | 否 | 0 | onboarding 是否已完成 |
| onboarding_operator_id | bigint unsigned | 是 | - |  |
| ssl_uploaded_at | timestamp | 是 | - |  |
| ssl_cert_expires_at | timestamp | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |
| deleted_at | timestamp | 是 | - |  |

## trusted_devices

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| trusted_device_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - |  |
| user_id | bigint unsigned | 否 | - |  |
| device_fingerprint | varchar(64) | 否 | - |  |
| device_name | varchar(200) | 是 | - |  |
| ip_address | varchar(45) | 是 | - |  |
| user_agent | varchar(500) | 是 | - |  |
| expires_at | timestamp | 是 | - |  |
| last_used_at | timestamp | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## usage_records

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| usage_record_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - |  |
| metric_type | varchar(50) | 否 | - |  |
| value | decimal(18,4) | 否 | - |  |
| period | varchar(7) | 否 | - | 计费周期，格式 YYYYMM |
| recorded_at | timestamp | 否 | - |  |
| metadata | json | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## user_api_token_histories

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | bigint unsigned | 否 | - |  |
| user_id | bigint unsigned | 否 | - |  |
| apisvr_token_id | int unsigned | 否 | - |  |
| apisvr_key_masked | varchar(100) | 否 | - | 掩码后的旧 Key |
| quota_at_rotation | int | 否 | 0 |  |
| reason | varchar(50) | 否 | - | leaked\|admin_reset\|user_request |
| rotated_by | bigint unsigned | 是 | - |  |
| rotated_at | timestamp | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## user_api_tokens

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | bigint unsigned | 否 | - |  |
| user_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - |  |
| apisvr_token_id | int unsigned | 否 | - | new-api 后端 token ID |
| apisvr_key | text | 否 | - | sk-xxx 格式的完整 API Key |
| remain_quota_cache | int | 否 | 0 |  |
| used_quota_cache | int | 否 | 0 |  |
| quota_synced_at | timestamp | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |
| deleted_at | timestamp | 是 | - |  |

## user_payment_passwords

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - |  |
| user_id | bigint unsigned | 否 | - |  |
| password_hash | varchar(255) | 否 | - |  |
| last_verified_at | timestamp | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## user_preferences

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| id | bigint unsigned | 否 | - |  |
| user_id | bigint unsigned | 否 | - |  |
| preferences | json | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## user_sessions

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| user_session_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - |  |
| user_id | bigint unsigned | 否 | - |  |
| token_id | bigint unsigned | 是 | - |  |
| session_id | varchar(100) | 是 | - |  |
| ip_address | varchar(45) | 是 | - |  |
| device_info | varchar(500) | 是 | - |  |
| device_fingerprint | varchar(64) | 是 | - |  |
| login_at | timestamp | 是 | - |  |
| last_active_at | timestamp | 是 | - |  |
| location | varchar(255) | 是 | - |  |
| is_anomalous | tinyint(1) | 否 | 0 |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## users

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| user_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 是 | - |  |
| name | varchar(255) | 否 | - |  |
| email | varchar(255) | 否 | - |  |
| email_verified_at | timestamp | 是 | - |  |
| password | varchar(255) | 否 | - |  |
| password_changed_at | timestamp | 是 | - |  |
| login_attempts | int unsigned | 否 | 0 |  |
| locked_until | timestamp | 是 | - |  |
| phone | varchar(20) | 是 | - |  |
| avatar | varchar(500) | 是 | - |  |
| is_active | tinyint(1) | 否 | 1 |  |
| last_active_at | timestamp | 是 | - |  |
| remember_token | varchar(100) | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |
| deleted_at | timestamp | 是 | - |  |

## vote_options

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| vote_option_id | bigint unsigned | 否 | - |  |
| vote_id | bigint unsigned | 否 | - | 所属投票 |
| title | varchar(255) | 否 | - | 选项标题 |
| image | varchar(255) | 是 | - | 选项图片 |
| description | varchar(255) | 是 | - | 选项描述 |
| vote_count | int unsigned | 否 | 0 | 得票数 |
| sort_order | smallint unsigned | 否 | 0 | 排序 |
| metadata | json | 是 | - | 附加元数据 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## vote_records

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| vote_record_id | bigint unsigned | 否 | - |  |
| vote_id | bigint unsigned | 否 | - | 所属投票 |
| vote_option_id | bigint unsigned | 否 | - | 投票选项 |
| user_id | bigint unsigned | 否 | - | 投票用户 |
| tenant_id | bigint unsigned | 否 | - | 租户 ID |
| ip_address | varchar(45) | 是 | - | IP 地址 |
| user_agent | varchar(255) | 是 | - | User Agent |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## votes

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| vote_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - | 租户 ID |
| title | varchar(255) | 否 | - | 投票标题 |
| description | varchar(255) | 是 | - | 投票描述 |
| vote_type | varchar(20) | 否 | single | 投票类型: single/multiple |
| status | varchar(20) | 否 | draft | 状态: draft/active/ended |
| start_at | timestamp | 否 | - | 开始时间 |
| end_at | timestamp | 否 | - | 结束时间 |
| daily_limit | int unsigned | 否 | 0 | 每日总投票上限，0=不限 |
| total_limit | int unsigned | 否 | 0 | 总投票上限，0=不限 |
| daily_limit_per_user | int unsigned | 否 | 1 | 每用户每日限制 |
| total_limit_per_user | int unsigned | 否 | 0 | 每用户总限制 |
| anti_cheat_ip | tinyint(1) | 否 | 1 | 是否启用 IP 防刷 |
| show_result | tinyint(1) | 否 | 1 | 是否显示结果 |
| show_rank | tinyint(1) | 否 | 1 | 是否显示排行 |
| total_votes | int unsigned | 否 | 0 | 总投票数 |
| metadata | json | 是 | - | 附加元数据 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## webhook_deliveries

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| webhook_delivery_id | bigint unsigned | 否 | - |  |
| webhook_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - |  |
| event_type | varchar(100) | 否 | - |  |
| payload | json | 否 | - |  |
| response_status_code | smallint unsigned | 是 | - |  |
| response_body | text | 是 | - |  |
| duration_ms | int unsigned | 是 | - |  |
| attempts | tinyint unsigned | 否 | 0 |  |
| status | varchar(20) | 否 | pending |  |
| error_message | text | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## webhooks

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| webhook_id | bigint unsigned | 否 | - |  |
| tenant_id | bigint unsigned | 否 | - |  |
| url | varchar(500) | 否 | - |  |
| events | json | 否 | - |  |
| secret | varchar(128) | 否 | - |  |
| is_active | tinyint(1) | 否 | 1 |  |
| description | varchar(255) | 是 | - |  |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |
| deleted_at | timestamp | 是 | - |  |

## workflow_executions

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| execution_id | bigint unsigned | 否 | - | 执行 ID（IdGenerator 全局ID） |
| workflow_id | bigint unsigned | 否 | - | 工作流 ID |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| status | varchar(20) | 否 | pending | 状态: pending/running/completed/failed/cancelled |
| context | json | 是 | - | 执行上下文（JSON） |
| error | text | 是 | - | 错误信息 |
| started_at | timestamp | 是 | - | 开始时间 |
| completed_at | timestamp | 是 | - | 完成时间 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## workflow_nodes

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| node_id | bigint unsigned | 否 | - | 节点 ID（IdGenerator 全局ID） |
| workflow_id | bigint unsigned | 否 | - | 所属工作流 ID |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| name | varchar(100) | 否 | - | 节点名称 |
| type | varchar(30) | 否 | - | 节点类型: start/end/condition/action/wait |
| config | json | 是 | - | 节点配置（JSON） |
| next_node_id | bigint unsigned | 是 | - | 下一节点 ID |
| order | int | 否 | 0 | 排序 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

## workflows

| 字段 | 类型 | 可空 | 默认值 | 说明 |
|---|---|---|---|---|
| workflow_id | bigint unsigned | 否 | - | 工作流 ID（IdGenerator 全局ID） |
| tenant_id | bigint unsigned | 否 | - | 租户ID |
| name | varchar(100) | 否 | - | 工作流名称 |
| description | varchar(500) | 是 | - | 工作流描述 |
| type | varchar(30) | 否 | sequential | 类型: sequential/parallel/conditional |
| status | varchar(20) | 否 | draft | 状态: draft/active/archived |
| version | int | 否 | 1 | 版本号 |
| config | json | 是 | - | 工作流配置（JSON） |
| enabled | tinyint(1) | 否 | 1 | 是否启用 |
| created_at | timestamp | 是 | - |  |
| updated_at | timestamp | 是 | - |  |

