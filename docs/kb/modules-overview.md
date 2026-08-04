---
title: 功能模块总览
audience: operator
locale: zh
version: 1.0
---

# 功能模块总览

系统由以下功能模块组成。各模块在 console 后台左侧菜单可见（按租户开通情况显示）。

## 用户与身份

- **Auth（认证）**：登录、注册、密码重置、第三方登录（OAuth）。
- **User（用户）**：前台用户管理、用户与租户的关联。
- **Operator（运营人员）**：后台运营人员账号与租户成员管理。
- **ApiToken（API 令牌）**：为开放 API 签发访问令牌。

## AI 能力

- **Ai（AI / 数字员工）**：数字员工（Agent）、AI 对话、页面智能助手、系统小秘书、MCP 工具、工作流联动。
- **AiStreaming（AI 流式推理）**：Node.js SSE 流式推理服务，PHP 契约 API（resolve / tools execute / usage report / messages report）。
- **Knowledge（知识库）**：租户自有知识库，文档上传、向量检索，供数字员工引用。

## 营销与互动

- **Coupon（优惠券）**：券模板、发放、核销。
- **Lottery（抽奖）**：抽奖活动配置与开奖。
- **Voting（投票）**：投票活动创建与统计。
- **Form（表单）**：自定义表单收集。

## 触达与消息

- **Channel（频道）**：框架级结构化渠道凭证管理（企微/Telegram/公众号/短信），channels 表存储。位于 `src/Services/Channel/`（非独立模块）。
- **Campaign（活动编排）**：活动计划编排（PlanCompiler）、锚点预检、定时执行、秘书工具（draft/commit/status）。
- **Conversation（会话）**：多渠道会话聚合与消息收发。
- **Notification（通知）**：站内信、邮件等通知发送。
- **Sms（短信）**：短信发送与批量任务。
- **Event（事件）**：系统事件总线，模块间联动的基础。

## 商业化

- **Commerce（商品交易）**：平台 SKU 目录（plan/module/credit_pack/content_pack/mall_supply）、租户下单→支付→履约、6 种履约 Handler、ModuleEntitlement 双层权益、供给授权（supply_grants）、平台内容库。
- **Billing（计费）**：套餐订阅、积分额度、用量记账。
- **Payment（支付）**：支付渠道对接与订单收款。

## 支撑与运维

- **Ibot（IM 机器人）**：Telegram/企微双频道 IM 机器人，L2 确认机制，AI 引导配置工具。
- **Ticket（工单）**：客户工单提交与处理。
- **Storage（存储）**：文件上传与管理。
- **Workflow（工作流）**：可视化流程编排与执行。
- **Domain（域名）/ SSL（证书）**：租户自定义域名与 HTTPS 证书。
- **Monitoring（监控）/ Logging（日志）**：系统运行监控与日志查询。
- **Platform（平台）/ Infrastructure（基础设施）**：平台级配置、租户开通、健康检查。
- **DeveloperPortal（开发者门户）/ Plugin（插件）**：开放能力与扩展插件。
