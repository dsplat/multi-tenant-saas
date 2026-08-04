---
title: 功能分布图
module: 
audience: internal
locale: zh
---

# 功能分布图

> 本文档由 `secretary:kb:generate` 自动生成，请勿手工编辑。

## admin

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/admin/plugins | - |
| POST | /api/v1/admin/plugins/{name}/install | - |
| POST | /api/v1/admin/plugins/{name}/uninstall | - |
| POST | /api/v1/admin/plugins/{name}/enable | - |
| POST | /api/v1/admin/plugins/{name}/disable | - |
| GET | /api/v1/admin/modules | - |
| POST | /api/v1/admin/modules/{name}/enable | - |
| POST | /api/v1/admin/modules/{name}/disable | - |
| GET | /api/v1/admin/system-settings | - |
| PUT | /api/v1/admin/system-settings/{group} | - |
| GET | /api/v1/admin/retention-policies | - |
| POST | /api/v1/admin/retention-policies | - |
| PUT | /api/v1/admin/retention-policies/{id} | - |
| DELETE | /api/v1/admin/retention-policies/{id} | - |
| GET | /api/v1/admin/consents | - |
| POST | /api/v1/admin/consents | - |
| POST | /api/v1/admin/consents/{id}/revoke | - |
| GET | /api/v1/admin/queue/failed | - |
| POST | /api/v1/admin/queue/failed/{id}/retry | - |
| DELETE | /api/v1/admin/queue/failed/{id} | - |
| POST | /api/v1/admin/queue/failed/retry-all | - |
| DELETE | /api/v1/admin/queue/failed | - |
| GET | /api/v1/admin/webhooks | - |
| GET | /api/v1/admin/webhooks/{id} | - |
| POST | /api/v1/admin/webhooks | - |
| PUT | /api/v1/admin/webhooks/{id} | - |
| DELETE | /api/v1/admin/webhooks/{id} | - |
| POST | /api/v1/admin/webhooks/{id}/test | - |
| GET | /api/v1/admin/ip-whitelist | - |
| POST | /api/v1/admin/ip-whitelist | - |
| DELETE | /api/v1/admin/ip-whitelist/{id} | - |
| GET | /api/v1/admin/feature-flags | - |
| GET | /api/v1/admin/feature-flags/{id} | - |
| POST | /api/v1/admin/feature-flags | - |
| PUT | /api/v1/admin/feature-flags/{id} | - |
| POST | /api/v1/admin/feature-flags/{id}/toggle | - |
| GET | /api/v1/admin/branding | - |
| PUT | /api/v1/admin/branding | - |
| GET | /api/v1/admin/tenant-keys | - |
| POST | /api/v1/admin/tenant-keys | - |
| DELETE | /api/v1/admin/tenant-keys/{id} | - |
| GET | /api/v1/admin/billing/plans | - |
| POST | /api/v1/admin/billing/plans | - |
| PUT | /api/v1/admin/billing/plans/{planId} | - |
| DELETE | /api/v1/admin/billing/plans/{planId} | - |
| GET | /api/v1/admin/billing/plans/{planId} | - |
| GET | /api/v1/admin/billing/credits/overview | - |
| POST | /api/v1/admin/billing/credits/batch-recharge | - |
| GET | /api/v1/admin/audit/logs | - |
| GET | /api/v1/admin/audit/logs/export | - |
| GET | /api/v1/admin/audit/logs/{id} | - |
| GET | /api/v1/admin/auth/user | - |
| POST | /api/v1/admin/auth/logout | - |
| POST | /api/v1/admin/auth/login | - |
| GET | /api/v1/admin/auth/permissions | - |
| GET | /api/v1/admin/auth/roles | - |
| POST | /api/v1/admin/auth/roles | - |
| PUT | /api/v1/admin/auth/roles/{roleId}/permissions | - |
| DELETE | /api/v1/admin/auth/roles/{roleId} | - |
| POST | /api/v1/admin/auth/members/{userId}/role | - |
| GET | /api/v1/admin/auth/sso/providers | - |
| POST | /api/v1/admin/auth/sso/providers | - |
| DELETE | /api/v1/admin/auth/sso/providers/{name} | - |
| GET | /api/v1/admin/operators | - |
| GET | /api/v1/admin/operators/{operatorId} | - |
| POST | /api/v1/admin/operators/invite | - |
| PUT | /api/v1/admin/operators/{operatorId} | - |
| PUT | /api/v1/admin/operators/{operatorId}/role | - |
| POST | /api/v1/admin/operators/{operatorId}/toggle-status | - |
| POST | /api/v1/admin/operators/{operatorId}/resend-invite | - |
| DELETE | /api/v1/admin/operators/{operatorId} | - |
| GET | /api/v1/admin/operators/{operatorId}/tenants | - |
| GET | /api/v1/admin/files | - |
| GET | /api/v1/admin/files/usage | - |
| DELETE | /api/v1/admin/files/{id} | - |
| GET | /api/v1/admin/monitoring/metrics | - |
| GET | /api/v1/admin/monitoring/alerts | - |
| GET | /api/v1/admin/monitoring/health | - |
| GET | /api/v1/admin/settings | - |
| PUT | /api/v1/admin/settings/{group} | - |
| GET | /api/v1/admin/applications | - |
| GET | /api/v1/admin/applications/{id} | - |
| POST | /api/v1/admin/applications/{id}/approve | - |
| POST | /api/v1/admin/applications/{id}/reject | - |
| GET | /api/v1/admin/apply-fields | - |
| PUT | /api/v1/admin/apply-fields | - |
| GET | /api/v1/admin/tenants | - |
| POST | /api/v1/admin/tenants | - |
| GET | /api/v1/admin/tenants/{tenantId} | - |
| PUT | /api/v1/admin/tenants/{tenantId} | - |
| DELETE | /api/v1/admin/tenants/{tenantId} | - |
| POST | /api/v1/admin/tenants/{tenantId}/suspend | - |
| POST | /api/v1/admin/tenants/{tenantId}/activate | - |
| GET | /api/v1/admin/tenants/{tenantId}/members | - |
| POST | /api/v1/admin/tenants/{tenantId}/members | - |
| PUT | /api/v1/admin/tenants/{tenantId}/members/{userId} | - |
| DELETE | /api/v1/admin/tenants/{tenantId}/members/{userId} | - |
| GET | /api/v1/admin/tenants/{tenantId}/users | - |
| GET | /api/v1/admin/tenants/{tenantId}/users/search | - |
| GET | /api/v1/admin/tenants/{tenantId}/users/{userId} | - |
| PUT | /api/v1/admin/tenants/{tenantId}/users/{userId} | - |
| DELETE | /api/v1/admin/tenants/{tenantId}/users/{userId} | - |
| GET | /api/v1/admin/users/search | - |
| GET | /api/v1/admin/developer-portal/sandbox | - |
| POST | /api/v1/admin/developer-portal/sandbox | - |
| GET | /api/v1/admin/workflows | - |
| POST | /api/v1/admin/workflows | - |
| PUT | /api/v1/admin/workflows/{id} | - |
| DELETE | /api/v1/admin/workflows/{id} | - |
| GET | /api/v1/admin/domains | - |
| POST | /api/v1/admin/domains/{tenantId} | - |
| PUT | /api/v1/admin/domains/{tenantId} | - |
| DELETE | /api/v1/admin/domains/{tenantId} | - |
| POST | /api/v1/admin/domains/{tenantId}/approve | - |
| POST | /api/v1/admin/domains/{tenantId}/reject | - |
| GET | /api/v1/admin/reserved-domains | - |
| PUT | /api/v1/admin/reserved-domains | - |
| GET | /api/v1/admin/ssl | - |
| POST | /api/v1/admin/ssl/{tenantId} | - |
| DELETE | /api/v1/admin/ssl/{tenantId} | - |
| POST | /api/v1/admin/ssl/{tenantId}/renew | - |
| GET | /api/v1/admin/payments/config | - |
| PUT | /api/v1/admin/payments/config/{driver} | - |
| GET | /api/v1/admin/payments/orders | - |
| GET | /api/v1/admin/payments/orders/{orderId} | - |
| GET | /api/v1/admin/api-tokens | - |
| DELETE | /api/v1/admin/api-tokens/{tenantId}/{tokenId} | - |
| GET | /api/v1/admin/commerce/skus | - |
| POST | /api/v1/admin/commerce/skus | - |
| PUT | /api/v1/admin/commerce/skus/{skuId} | - |
| DELETE | /api/v1/admin/commerce/skus/{skuId} | - |
| GET | /api/v1/admin/commerce/orders | - |
| POST | /api/v1/admin/commerce/retry | - |
| GET | /api/v1/admin/commerce/supply-grants | - |
| POST | /api/v1/admin/commerce/supply-grants/{grantId}/suspend | - |
| POST | /api/v1/admin/commerce/supply-grants/{grantId}/resume | - |
| GET | /api/v1/admin/commerce/content-library | - |
| POST | /api/v1/admin/commerce/content-library | - |
| PUT | /api/v1/admin/commerce/content-library/{contentId} | - |
| POST | /api/v1/admin/commerce/content-library/{contentId}/publish | - |
| DELETE | /api/v1/admin/commerce/content-library/{contentId} | - |
| GET | /api/v1/admin/commerce/content-packs | - |
| POST | /api/v1/admin/commerce/content-packs | - |
| GET | /api/v1/admin/commerce/content-packs/{packId} | - |
| PUT | /api/v1/admin/commerce/content-packs/{packId} | - |
| DELETE | /api/v1/admin/commerce/content-packs/{packId} | - |

## agents

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/agents | - |
| GET | /api/v1/agents/templates | - |
| POST | /api/v1/agents/templates/{templateId}/clone | - |
| GET | /api/v1/agents/{agentId} | - |
| POST | /api/v1/agents | - |
| PUT | /api/v1/agents/{agentId} | - |
| POST | /api/v1/agents/{agentId}/enable | - |
| POST | /api/v1/agents/{agentId}/disable | - |
| PUT | /api/v1/agents/{agentId}/model-config | - |
| PUT | /api/v1/agents/{agentId}/tools | - |
| PUT | /api/v1/agents/{agentId}/knowledge-bases | - |
| DELETE | /api/v1/agents/{agentId} | - |
| POST | /api/v1/agents/{agentId}/chat | - |
| POST | /api/v1/agents/{agentId}/chat/{conversationId} | - |
| GET | /api/v1/agents/{agentId}/conversations | - |
| GET | /api/v1/agents/{agentId}/stats | - |
| GET | /api/v1/agents/{agentId}/token-usage | - |
| GET | /api/v1/agents/{agentId}/cost | - |
| GET | /api/v1/agents/{agentId}/tool-logs | - |

## ai

| 方法 | 路径 | 路由名 |
|---|---|---|
| POST | /api/v1/ai/assistant | - |
| GET | /api/v1/ai/assistant/availability | - |

## ai-streaming

| 方法 | 路径 | 路由名 |
|---|---|---|
| POST | /api/v1/ai-streaming/resolve | - |
| POST | /api/v1/ai-streaming/tools/execute | - |
| POST | /api/v1/ai-streaming/usage/report | - |
| POST | /api/v1/ai-streaming/messages/report | - |

## api-tokens

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/api-tokens | - |
| DELETE | /api/v1/api-tokens/{tenantId}/{tokenId} | - |

## audit

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/audit/logs | - |
| GET | /api/v1/audit/logs/export | - |
| GET | /api/v1/audit/logs/{id} | - |

## auth

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/auth/{provider}/redirect | - |
| GET | /api/v1/auth/{provider}/callback | - |
| GET | /api/v1/auth/me | - |
| POST | /api/v1/auth/logout | - |
| POST | /api/v1/auth/mfa/verify | - |
| POST | /api/v1/auth/bind-contact | - |
| POST | /api/v1/auth/bind-contact/send-email-code | - |
| PUT | /api/v1/auth/profile | - |
| PUT | /api/v1/auth/password | - |
| GET | /api/v1/auth/oauth-bindings | - |
| DELETE | /api/v1/auth/oauth-bindings/{provider} | - |
| POST | /api/v1/auth/login | - |
| POST | /api/v1/auth/register | - |
| POST | /api/v1/auth/forgot-password | - |
| POST | /api/v1/auth/reset-password | - |
| POST | /api/v1/auth/verify-email | - |
| POST | /api/v1/auth/resend-verification | - |
| POST | /api/v1/auth/sms/send-code | - |
| POST | /api/v1/auth/sms/login | - |
| GET | /api/v1/auth/sso/{provider}/redirect | - |
| GET | /api/v1/auth/sso/{provider}/callback | - |
| POST | /api/v1/auth/sso/{provider}/callback | - |

## broadcast

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/broadcast/history | - |
| GET | /api/v1/broadcast/status | - |
| POST | /api/v1/broadcast/retry | - |

## capabilities

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/capabilities | - |
| POST | /api/v1/capabilities/execute | - |
| POST | /api/v1/capabilities/batch | - |

## commerce

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/commerce/skus | - |
| GET | /api/v1/commerce/skus/{skuId} | - |
| POST | /api/v1/commerce/orders | - |
| GET | /api/v1/commerce/orders | - |
| GET | /api/v1/commerce/orders/{orderId} | - |
| POST | /api/v1/commerce/orders/{orderId}/pay | - |
| POST | /api/v1/commerce/orders/{orderId}/cancel | - |
| GET | /api/v1/commerce/supply-grants | - |
| GET | /api/v1/commerce/supply-grants/{grantId} | - |
| GET | /api/v1/commerce/content-packs | - |
| GET | /api/v1/commerce/content-packs/{packId} | - |

## channels

| 方法 | 路径 | 路由名 |
|---|---|---|
| POST | /api/v1/channels/enterprise-wechat/webhook | - |
| POST | /api/v1/channels/wechat-official/webhook | - |

## console

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/console/auth/user | - |
| POST | /api/v1/console/auth/logout | - |
| POST | /api/v1/console/auth/login | - |

## conversations

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/conversations/{conversationId} | - |
| GET | /api/v1/conversations/{conversationId}/messages | - |
| DELETE | /api/v1/conversations/{conversationId} | - |

## conversations-center

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/conversations-center | - |
| POST | /api/v1/conversations-center | - |
| GET | /api/v1/conversations-center/{conversationId} | - |
| POST | /api/v1/conversations-center/{conversationId}/close | - |
| POST | /api/v1/conversations-center/{conversationId}/archive | - |
| GET | /api/v1/conversations-center/{conversationId}/messages | - |
| POST | /api/v1/conversations-center/{conversationId}/messages | - |
| POST | /api/v1/conversations-center/{conversationId}/participants | - |
| GET | /api/v1/conversations-center/{conversationId}/sessions | - |
| POST | /api/v1/conversations-center/{conversationId}/sessions | - |
| GET | /api/v1/conversations-center/{conversationId}/tags | - |
| POST | /api/v1/conversations-center/{conversationId}/tags | - |
| POST | /api/v1/conversations-center/messages/{messageId}/reactions | - |
| POST | /api/v1/conversations-center/messages/{messageId}/read | - |
| DELETE | /api/v1/conversations-center/participants/{participantId} | - |
| POST | /api/v1/conversations-center/sessions/{sessionId}/close | - |
| DELETE | /api/v1/conversations-center/messages/{messageId}/reactions/{emoji} | - |

## core

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/documentation | l5-swagger.default.api |
| GET | /api/oauth2-callback | l5-swagger.default.oauth2_callback |

## coupon-templates

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/coupon-templates | - |
| POST | /api/v1/coupon-templates | - |
| PUT | /api/v1/coupon-templates/{templateId} | - |
| DELETE | /api/v1/coupon-templates/{templateId} | - |
| PUT | /api/v1/coupon-templates/{templateId}/activate | - |
| PUT | /api/v1/coupon-templates/{templateId}/deactivate | - |
| POST | /api/v1/coupon-templates/{templateId}/generate | - |
| POST | /api/v1/coupon-templates/{templateId}/distribute | - |
| POST | /api/v1/coupon-templates/{templateId}/share | - |
| POST | /api/v1/coupon-templates/accept-share | - |

## coupons

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/coupons | - |
| POST | /api/v1/coupons | - |
| GET | /api/v1/coupons/{couponId} | - |
| PUT | /api/v1/coupons/{couponId}/activate | - |
| PUT | /api/v1/coupons/{couponId}/deactivate | - |
| POST | /api/v1/coupons/redeem | - |
| POST | /api/v1/coupons/validate | - |
| GET | /api/v1/coupons/{couponId}/usages | - |
| GET | /api/v1/coupons/{couponId}/statistics | - |

## developer-portal

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/developer-portal/sandbox | - |
| POST | /api/v1/developer-portal/sandbox | - |

## domains

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/domains | - |
| POST | /api/v1/domains/{tenantId} | - |
| PUT | /api/v1/domains/{tenantId} | - |
| DELETE | /api/v1/domains/{tenantId} | - |
| POST | /api/v1/domains/{tenantId}/approve | - |
| POST | /api/v1/domains/{tenantId}/reject | - |

## feature-flags

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/feature-flags | - |
| GET | /api/v1/feature-flags/{id} | - |
| POST | /api/v1/feature-flags | - |
| PUT | /api/v1/feature-flags/{id} | - |
| POST | /api/v1/feature-flags/{id}/toggle | - |

## files

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/files | - |
| POST | /api/v1/files | - |
| GET | /api/v1/files/usage | - |
| GET | /api/v1/files/{id} | - |
| GET | /api/v1/files/{id}/preview | - |
| GET | /api/v1/files/{id}/download | - |
| POST | /api/v1/files/{id}/share | - |
| POST | /api/v1/files/entity/{module}/{entityId} | - |
| GET | /api/v1/files/entity/{module}/{entityId} | - |
| GET | /api/v1/files/entity/{module}/{entityId}/url | - |
| DELETE | /api/v1/files/{id} | - |
| DELETE | /api/v1/files/entity/{module}/{entityId} | - |

## forms

| 方法 | 路径 | 路由名 |
|---|---|---|
| POST | /api/v1/forms/public/{formId}/submit | - |

## in-app-notifications

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/in-app-notifications | - |
| GET | /api/v1/in-app-notifications/categories | - |
| GET | /api/v1/in-app-notifications/unread-count | - |
| POST | /api/v1/in-app-notifications/{id}/read | - |
| POST | /api/v1/in-app-notifications/read/batch | - |
| POST | /api/v1/in-app-notifications/read-all | - |
| DELETE | /api/v1/in-app-notifications/{id} | - |
| DELETE | /api/v1/in-app-notifications/read/clear | - |
| GET | /api/v1/in-app-notifications/preferences | - |
| POST | /api/v1/in-app-notifications/preferences | - |
| POST | /api/v1/in-app-notifications/preferences/batch | - |

## mfa

| 方法 | 路径 | 路由名 |
|---|---|---|
| POST | /api/v1/mfa/totp/setup | - |
| POST | /api/v1/mfa/totp/confirm | - |
| POST | /api/v1/mfa/email/send | - |
| POST | /api/v1/mfa/sms/send | - |
| GET | /api/v1/mfa/devices | - |
| DELETE | /api/v1/mfa/devices/{deviceId} | - |
| PUT | /api/v1/mfa/devices/{deviceId} | - |
| POST | /api/v1/mfa/devices/{deviceId}/primary | - |
| POST | /api/v1/mfa/recovery-codes/generate | - |
| GET | /api/v1/mfa/recovery-codes/status | - |
| GET | /api/v1/mfa/sessions | - |
| DELETE | /api/v1/mfa/sessions/{sessionId} | - |
| POST | /api/v1/mfa/sessions/revoke-all | - |

## monitoring

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/monitoring/metrics | - |
| GET | /api/v1/monitoring/alerts | - |
| GET | /api/v1/monitoring/health | - |

## notifications

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/notifications | - |
| GET | /api/v1/notifications/unread-count | - |
| POST | /api/v1/notifications/{id}/read | - |
| POST | /api/v1/notifications/read-all | - |
| DELETE | /api/v1/notifications/{id} | - |
| DELETE | /api/v1/notifications/read/clear | - |
| GET | /api/v1/notifications/preferences | - |
| POST | /api/v1/notifications/preferences | - |
| POST | /api/v1/notifications/preferences/batch | - |

## operator

| 方法 | 路径 | 路由名 |
|---|---|---|
| POST | /api/v1/operator/accept-invite | - |
| POST | /api/v1/operator/apply | - |
| GET | /api/v1/operator/applications | - |

## operator-auth

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/operator-auth/me | - |
| POST | /api/v1/operator-auth/logout | - |
| POST | /api/v1/operator-auth/register | - |
| POST | /api/v1/operator-auth/login | - |
| POST | /api/v1/operator-auth/verify-email | - |
| POST | /api/v1/operator-auth/resend-verification | - |
| POST | /api/v1/operator-auth/forgot-password | - |
| POST | /api/v1/operator-auth/reset-password | - |

## operators

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/operators | - |
| GET | /api/v1/operators/{operatorId} | - |
| PUT | /api/v1/operators/{operatorId} | - |
| POST | /api/v1/operators/invite | - |
| PUT | /api/v1/operators/{operatorId}/role | - |
| DELETE | /api/v1/operators/{operatorId} | - |
| GET | /api/v1/operators/{operatorId}/tenants | - |
| POST | /api/v1/operators/{operatorId}/toggle-status | - |
| POST | /api/v1/operators/{operatorId}/resend-invite | - |

## pay

| 方法 | 路径 | 路由名 |
|---|---|---|
| POST | /api/v1/pay/wechat/notify | - |
| POST | /api/v1/pay/alipay/notify | - |
| GET | /api/v1/pay/wechat/notify | - |
| GET | /api/v1/pay/alipay/notify | - |
| POST | /api/v1/pay/wechat/refund-notify | - |
| POST | /api/v1/pay/alipay/refund-notify | - |

## payments

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/payments/config | - |
| PUT | /api/v1/payments/config/{driver} | - |
| GET | /api/v1/payments/orders | - |
| GET | /api/v1/payments/orders/{orderId} | - |

## public

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/public/apply/{code} | - |
| GET | /api/v1/public/apply-fields | - |
| GET | /api/v1/public/site-config | - |
| POST | /api/v1/commerce/pay/wechat/notify | - |
| POST | /api/v1/commerce/pay/alipay/notify | - |
| GET | /api/v1/commerce/pay/alipay/notify | - |

## reserved-domains

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/reserved-domains | - |
| PUT | /api/v1/reserved-domains | - |

## ssl

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/ssl | - |
| POST | /api/v1/ssl/{tenantId} | - |
| DELETE | /api/v1/ssl/{tenantId} | - |
| POST | /api/v1/ssl/{tenantId}/renew | - |

## subscription

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/subscription/plans | - |
| GET | /api/v1/subscription/plans/{planId} | - |
| POST | /api/v1/subscription/plans | - |
| PUT | /api/v1/subscription/plans/{planId} | - |
| DELETE | /api/v1/subscription/plans/{planId} | - |

## tenant

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/tenant/events | - |
| GET | /api/v1/tenant/auth/mfa/devices | - |
| DELETE | /api/v1/tenant/auth/mfa/devices/{deviceId} | - |
| PUT | /api/v1/tenant/auth/mfa/devices/{deviceId} | - |
| POST | /api/v1/tenant/auth/mfa/devices/{deviceId}/primary | - |
| POST | /api/v1/tenant/auth/mfa/recovery-codes/generate | - |
| GET | /api/v1/tenant/auth/mfa/recovery-codes/status | - |
| GET | /api/v1/tenant/auth/mfa/sessions | - |
| DELETE | /api/v1/tenant/auth/mfa/sessions/{sessionId} | - |
| POST | /api/v1/tenant/auth/mfa/sessions/revoke-all | - |
| GET | /api/v1/tenant/auth/oauth/config | - |
| PUT | /api/v1/tenant/auth/oauth/{provider} | - |
| GET | /api/v1/tenant/auth/mail/config | - |
| PUT | /api/v1/tenant/auth/mail/config | - |
| POST | /api/v1/tenant/auth/mail/test | - |
| GET | /api/v1/tenant/storage/config | - |
| PUT | /api/v1/tenant/storage/config | - |
| GET | /api/v1/tenant/files | - |
| POST | /api/v1/tenant/files | - |
| GET | /api/v1/tenant/files/usage | - |
| GET | /api/v1/tenant/files/{id} | - |
| DELETE | /api/v1/tenant/files/{id} | - |
| GET | /api/v1/tenant/notifications | - |
| GET | /api/v1/tenant/notifications/unread-count | - |
| POST | /api/v1/tenant/notifications/{id}/read | - |
| POST | /api/v1/tenant/notifications/read-all | - |
| DELETE | /api/v1/tenant/notifications/{id} | - |
| GET | /api/v1/tenant/notifications/preferences | - |
| POST | /api/v1/tenant/notifications/preferences | - |
| GET | /api/v1/tenant/monitoring/metrics | - |
| GET | /api/v1/tenant/developer/docs | - |
| GET | /api/v1/tenant/developer/sandbox | - |
| GET | /api/v1/tenant/conversations | - |
| POST | /api/v1/tenant/conversations | - |
| GET | /api/v1/tenant/workflows | - |
| GET | /api/v1/tenant/workflows/{id} | - |
| POST | /api/v1/tenant/workflows/{id}/execute | - |
| GET | /api/v1/tenant/resolve | - |
| GET | /api/v1/tenant/login-config | - |
| GET | /api/v1/tenant/{tenantId}/domain | - |
| POST | /api/v1/tenant/{tenantId}/domain | - |
| PUT | /api/v1/tenant/{tenantId}/domain | - |
| DELETE | /api/v1/tenant/{tenantId}/domain | - |
| POST | /api/v1/tenant/{tenantId}/domain/verify-token | - |
| POST | /api/v1/tenant/{tenantId}/domain/verify | - |
| GET | /api/v1/tenant/{tenantId}/domain/verify-info | - |
| GET | /api/v1/tenant/ssl | - |
| POST | /api/v1/tenant/ssl | - |
| DELETE | /api/v1/tenant/ssl | - |
| GET | /api/v1/tenant/payment/config | - |
| PUT | /api/v1/tenant/payment/{driver} | - |
| GET | /api/v1/tenant/payment/orders | - |
| POST | /api/v1/tenant/payment/orders | - |
| GET | /api/v1/tenant/payment/orders/{orderId} | - |
| GET | /api/v1/tenant/api-tokens | - |
| POST | /api/v1/tenant/api-tokens | - |
| DELETE | /api/v1/tenant/api-tokens/{tokenId} | - |
| GET | /api/v1/tenant/api-tokens/abilities | - |
| GET | /api/v1/tenant/external-kb/connections | - |
| POST | /api/v1/tenant/external-kb/connections | - |
| PUT | /api/v1/tenant/external-kb/connections/{connectionId} | - |
| DELETE | /api/v1/tenant/external-kb/connections/{connectionId} | - |
| POST | /api/v1/tenant/external-kb/connections/{connectionId}/test | - |

## tenants

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/tenants/{tenantId}/modules | - |
| POST | /api/v1/tenants/{tenantId}/modules/{name}/enable | - |
| POST | /api/v1/tenants/{tenantId}/modules/{name}/disable | - |
| GET | /api/v1/tenants/{tenantId}/webhooks | - |
| GET | /api/v1/tenants/{tenantId}/webhooks/{id} | - |
| POST | /api/v1/tenants/{tenantId}/webhooks | - |
| PUT | /api/v1/tenants/{tenantId}/webhooks/{id} | - |
| DELETE | /api/v1/tenants/{tenantId}/webhooks/{id} | - |
| POST | /api/v1/tenants/{tenantId}/webhooks/{id}/test | - |
| GET | /api/v1/tenants/{tenantId}/ip-whitelist | - |
| POST | /api/v1/tenants/{tenantId}/ip-whitelist | - |
| DELETE | /api/v1/tenants/{tenantId}/ip-whitelist/{id} | - |
| GET | /api/v1/tenants/{tenantId}/branding | - |
| PUT | /api/v1/tenants/{tenantId}/branding | - |
| GET | /api/v1/tenants/{tenantId}/tenant-keys | - |
| POST | /api/v1/tenants/{tenantId}/tenant-keys | - |
| DELETE | /api/v1/tenants/{tenantId}/tenant-keys/{id} | - |
| GET | /api/v1/tenants/{tenantId}/credits | - |
| GET | /api/v1/tenants/{tenantId}/quotas | - |
| GET | /api/v1/tenants/{tenantId}/subscription | - |
| POST | /api/v1/tenants/{tenantId}/subscription/subscribe | - |
| POST | /api/v1/tenants/{tenantId}/subscription/cancel | - |
| POST | /api/v1/tenants/{tenantId}/subscription/change | - |
| GET | /api/v1/tenants/{tenantId}/subscription/history | - |
| GET | /api/v1/tenants/{tenantId}/audit-logs | - |
| GET | /api/v1/tenants | - |
| GET | /api/v1/tenants/{tenantId} | - |
| POST | /api/v1/tenants | - |
| PUT | /api/v1/tenants/{tenantId} | - |
| POST | /api/v1/tenants/{tenantId}/suspend | - |
| POST | /api/v1/tenants/{tenantId}/activate | - |
| DELETE | /api/v1/tenants/{tenantId} | - |
| GET | /api/v1/tenants/{tenantId}/members | - |
| POST | /api/v1/tenants/{tenantId}/members | - |
| PUT | /api/v1/tenants/{tenantId}/members/{userId} | - |
| DELETE | /api/v1/tenants/{tenantId}/members/{userId} | - |
| GET | /api/v1/tenants/{tenantId}/settings/{group?} | - |
| PUT | /api/v1/tenants/{tenantId}/settings/{group} | - |
| POST | /api/v1/tenants/{tenantId}/settings/sms/test | - |
| GET | /api/v1/tenants/{tenantId}/users | - |
| GET | /api/v1/tenants/{tenantId}/users/search | - |
| GET | /api/v1/tenants/{tenantId}/users/{userId} | - |
| PUT | /api/v1/tenants/{tenantId}/users/{userId} | - |
| DELETE | /api/v1/tenants/{tenantId}/users/{userId} | - |
| POST | /api/v1/tenants/onboarding/start | - |
| POST | /api/v1/tenants/onboarding/status | - |
| POST | /api/v1/tenants/onboarding/complete | - |
| POST | /api/v1/tenants/onboarding/{step} | - |
| GET | /api/v1/tenants/{tenantId}/domain | - |
| PUT | /api/v1/tenants/{tenantId}/domain | - |
| POST | /api/v1/tenants/{tenantId}/domain/approve | - |
| POST | /api/v1/tenants/{tenantId}/domain/reject | - |
| GET | /api/v1/tenants/{tenantId}/ssl | - |
| POST | /api/v1/tenants/{tenantId}/ssl | - |
| DELETE | /api/v1/tenants/{tenantId}/ssl | - |
| GET | /api/v1/tenants/{tenantId}/payment/config | - |
| GET | /api/v1/tenants/{tenantId}/payment-orders | - |
| GET | /api/v1/tenants/{tenantId}/payment-orders/refund-status | - |
| PUT | /api/v1/tenants/{tenantId}/payment/{driver} | - |
| POST | /api/v1/tenants/{tenantId}/payment-orders | - |
| POST | /api/v1/tenants/{tenantId}/payment-orders/refund | - |
| GET | /api/v1/tenants/{tenantId}/api-tokens | - |
| GET | /api/v1/tenants/{tenantId}/api-tokens/abilities | - |
| POST | /api/v1/tenants/{tenantId}/api-tokens | - |
| DELETE | /api/v1/tenants/{tenantId}/api-tokens/{tokenId} | - |
| GET | /api/v1/tenants/{tenantId}/coupon-shares | - |
| GET | /api/v1/tenants/{tenantId}/forms | - |
| POST | /api/v1/tenants/{tenantId}/forms | - |
| GET | /api/v1/tenants/{tenantId}/forms/{formId} | - |
| PUT | /api/v1/tenants/{tenantId}/forms/{formId} | - |
| DELETE | /api/v1/tenants/{tenantId}/forms/{formId} | - |
| GET | /api/v1/tenants/{tenantId}/forms/{formId}/submissions | - |
| GET | /api/v1/tenants/{tenantId}/forms/{formId}/statistics | - |
| GET | /api/v1/tenants/{tenantId}/forms/{formId}/export | - |
| GET | /api/v1/tenants/{tenantId}/lottery | - |
| POST | /api/v1/tenants/{tenantId}/lottery | - |
| GET | /api/v1/tenants/{tenantId}/lottery/{activityId} | - |
| PUT | /api/v1/tenants/{tenantId}/lottery/{activityId} | - |
| DELETE | /api/v1/tenants/{tenantId}/lottery/{activityId} | - |
| PUT | /api/v1/tenants/{tenantId}/lottery/{activityId}/status | - |
| GET | /api/v1/tenants/{tenantId}/lottery/{activityId}/prizes | - |
| POST | /api/v1/tenants/{tenantId}/lottery/{activityId}/prizes | - |
| PUT | /api/v1/tenants/{tenantId}/lottery/{activityId}/prizes/{prizeId} | - |
| DELETE | /api/v1/tenants/{tenantId}/lottery/{activityId}/prizes/{prizeId} | - |
| POST | /api/v1/tenants/{tenantId}/lottery/{activityId}/draw | - |
| GET | /api/v1/tenants/{tenantId}/lottery/{activityId}/blacklist | - |
| POST | /api/v1/tenants/{tenantId}/lottery/{activityId}/blacklist | - |
| DELETE | /api/v1/tenants/{tenantId}/lottery/{activityId}/blacklist | - |
| GET | /api/v1/tenants/{tenantId}/lottery/{activityId}/statistics | - |
| GET | /api/v1/tenants/{tenantId}/lottery/{activityId}/my-logs | - |
| GET | /api/v1/tenants/{tenantId}/lottery/{activityId}/win-logs | - |
| GET | /api/v1/tenants/{tenantId}/lottery/{activityId}/export | - |
| GET | /api/v1/tenants/{tenantId}/sms/templates | - |
| POST | /api/v1/tenants/{tenantId}/sms/templates | - |
| GET | /api/v1/tenants/{tenantId}/sms/templates/{templateId} | - |
| PUT | /api/v1/tenants/{tenantId}/sms/templates/{templateId} | - |
| DELETE | /api/v1/tenants/{tenantId}/sms/templates/{templateId} | - |
| POST | /api/v1/tenants/{tenantId}/sms/templates/{templateId}/submit-approval | - |
| POST | /api/v1/tenants/{tenantId}/sms/templates/{templateId}/render | - |
| POST | /api/v1/tenants/{tenantId}/sms/batch-send | - |
| POST | /api/v1/tenants/{tenantId}/sms/scheduled-send | - |
| GET | /api/v1/tenants/{tenantId}/sms/batch-tasks/{taskId} | - |
| POST | /api/v1/tenants/{tenantId}/sms/batch-tasks/{taskId}/cancel | - |
| GET | /api/v1/tenants/{tenantId}/sms/batch-tasks/{taskId}/delivery-stats | - |
| GET | /api/v1/tenants/{tenantId}/sms/overall-stats | - |
| GET | /api/v1/tenants/{tenantId}/sms/unsubscribes | - |
| POST | /api/v1/tenants/{tenantId}/sms/unsubscribes | - |
| POST | /api/v1/tenants/{tenantId}/sms/unsubscribes/check | - |
| GET | /api/v1/tenants/{tenantId}/voting | - |
| POST | /api/v1/tenants/{tenantId}/voting | - |
| GET | /api/v1/tenants/{tenantId}/voting/{voteId} | - |
| PUT | /api/v1/tenants/{tenantId}/voting/{voteId} | - |
| DELETE | /api/v1/tenants/{tenantId}/voting/{voteId} | - |
| POST | /api/v1/tenants/{tenantId}/voting/{voteId}/cast | - |
| GET | /api/v1/tenants/{tenantId}/voting/{voteId}/ranking | - |
| GET | /api/v1/tenants/{tenantId}/voting/{voteId}/statistics | - |
| GET | /api/v1/tenants/{tenantId}/voting/{voteId}/records | - |

## tools

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/tools | - |
| GET | /api/v1/tools/{slug} | - |
| POST | /api/v1/tools | - |
| PUT | /api/v1/tools/{slug} | - |
| DELETE | /api/v1/tools/{slug} | - |

## workflows

| 方法 | 路径 | 路由名 |
|---|---|---|
| GET | /api/v1/workflows | - |
| POST | /api/v1/workflows | - |
| PUT | /api/v1/workflows/{id} | - |
| DELETE | /api/v1/workflows/{id} | - |

