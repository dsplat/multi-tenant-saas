---
title: 控制台页面路由地图
module: 
audience: operator
locale: zh
---

# 控制台页面路由地图

> 本文档列出控制台（/console/）所有可导航页面的路由路径。
> AI 小助手在带路（navigate）或正文引用页面时，**必须**使用本文档中的路径，禁止猜测。

## 使用方式

- navigate 工具的 `route_path` 参数：填写"路由路径"列的值（以 `/` 开头）
- Markdown 链接：`[页面名称](/路由路径)`
- 带参数路径（如 `:id`）：替换为实际 ID，如 `/tenants/42`

---

## 框架基础页面

| 页面名称 | 路由路径 | 说明 |
|---------|---------|------|
| 工作台 | /dashboard | 控制台首页 |
| 成员管理 | /members | 租户成员列表 |
| 租户设置 | /settings | 当前租户基础设置 |
| API 令牌 | /api-tokens | 开发者 API Token 管理 |
| OAuth 配置 | /oauth | 第三方登录配置 |
| 支付配置 | /payment | 支付渠道配置 |
| 短信配置 | /sms | 短信服务配置（非营销） |
| 存储配置 | /storage | 对象存储配置 |
| 外部知识库 | /external-kb | 外部知识库连接管理 |
| Webhooks | /webhooks | Webhook 订阅管理 |
| SSL 证书 | /ssl | SSL 证书管理 |
| 工作流 | /workflows | 工作流列表 |
| 工单 | /tickets | 工单管理 |
| 邮件模板 | /mail-templates | 邮件通知模板管理 |

## 计费与配额

| 页面名称 | 路由路径 | 说明 |
|---------|---------|------|
| 积分账户 | /billing/credits | 积分余额与流水 |
| 配额用量 | /billing/quotas | 资源配额与使用量 |
| 积分管理（全局） | /credits | 管理员积分总览 |
| 配额管理（全局） | /quotas | 管理员配额总览 |

## 租户管理（平台管理员）

| 页面名称 | 路由路径 | 说明 |
|---------|---------|------|
| 租户详情 | /tenants/:id | 单个租户详情页（替换 :id） |
| 申请创建团队 | /apply | 用户申请创建租户 |
| 我的申请 | /my-applications | 查看自己的申请记录 |
