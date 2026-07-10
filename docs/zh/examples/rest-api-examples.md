# REST API 调用示例

**最后更新**: 2026-06-29

---

## 通用约定

- Base URL: `https://api.example.com/api/v1`
- 鉴权: `Authorization: Bearer <token>`
- 请求/响应: `application/json`
- 响应体: `{ "success": bool, "data": ..., "message": "..." }`

以下示例使用 `curl`，可平移到任意 HTTP 客户端。

---

## 1. 认证

### 登录

```bash
curl -X POST https://api.example.com/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@example.com",
    "password": "Password123"
  }'
```

响应：

```json
{
  "success": true,
  "data": {
    "user": { "user_id": "1701...", "name": "管理员", "email": "admin@example.com" },
    "token": "1|abcdef..."
  }
}
```

### 当前用户

```bash
curl https://api.example.com/api/v1/auth/me \
  -H "Authorization: Bearer 1|abcdef..."
```

---

## 2. 租户管理

```bash
# 列出租户（super_admin）
curl https://api.example.com/api/v1/tenants \
  -H "Authorization: Bearer $TOKEN"

# 创建租户
curl -X POST https://api.example.com/api/v1/tenants \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "示例企业",
    "slug": "example",
    "custom_domain": "ai.example.com"
  }'

# 暂停租户
curl -X POST https://api.example.com/api/v1/tenants/1234567890123456/suspend \
  -H "Authorization: Bearer $TOKEN"
```

---

## 3. 成员与 RBAC

```bash
# 成员列表
curl https://api.example.com/api/v1/tenants/1234567890123456/members \
  -H "Authorization: Bearer $TOKEN"

# 创建角色
curl -X POST https://api.example.com/api/v1/tenants/1234567890123456/roles \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name": "运营专员"}'

# 分配成员角色
curl -X POST https://api.example.com/api/v1/tenants/1234567890123456/members/1701.../role \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"role_id": "1802..."}'
```

---

## 4. 积分与配额

```bash
# 租户积分账户
curl https://api.example.com/api/v1/tenants/1234567890123456/credits \
  -H "Authorization: Bearer $TOKEN"

# 配额
curl https://api.example.com/api/v1/tenants/1234567890123456/quotas \
  -H "Authorization: Bearer $TOKEN"
```

---

## 5. 订阅

```bash
# 订阅计划列表
curl https://api.example.com/api/v1/subscription/plans

# 订阅
curl -X POST https://api.example.com/api/v1/tenants/1234567890123456/subscription/subscribe \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"plan_id": "1803...", "billing_cycle": "monthly"}'
```

---

## 6. 支付

```bash
# 创建支付订单
curl -X POST https://api.example.com/api/v1/tenants/1234567890123456/payment-orders \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "driver": "alipay",
    "amount": 99.00,
    "subject": "Pro 订阅",
    "out_trade_no": "ORD1719000000"
  }'

# 退款
curl -X POST https://api.example.com/api/v1/tenants/1234567890123456/payment-orders/refund \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"out_trade_no": "ORD1719000000", "refund_amount": 99.00}'
```

---

## 7. 文件

```bash
# 上传
curl -X POST https://api.example.com/api/v1/files \
  -H "Authorization: Bearer $TOKEN" \
  -F "file=@/path/to/file.png"

# 下载
curl https://api.example.com/api/v1/files/1701.../download \
  -H "Authorization: Bearer $TOKEN" -o file.png

# 生成分享链接（无需认证的签名下载）
curl -X POST https://api.example.com/api/v1/files/1701.../share \
  -H "Authorization: Bearer $TOKEN"
```

---

## 8. AI 调用

### 文本

```bash
curl -X POST https://api.example.com/api/v1/ai/text \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "gpt-4o-mini",
    "messages": [{"role":"user","content":"一句话介绍多租户"}],
    "options": {"temperature":0.7,"max_tokens":256}
  }'
```

### 图像

```bash
curl -X POST https://api.example.com/api/v1/ai/image \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "prompt":"赛博朋克猫",
    "options":{"provider":"dalle","model":"dall-e-3","size":"1024x1024"}
  }'
```

### 视频（异步）

```bash
curl -X POST https://api.example.com/api/v1/ai/video \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "prompt":"日落延时摄影",
    "options":{"provider":"runway","model":"gen-3","duration":5}
  }'
```

### 用量

```bash
curl "https://api.example.com/api/v1/ai/usage?period=2026-06" \
  -H "Authorization: Bearer $TOKEN"
```

---

## 9. Webhook 管理

```bash
# 创建 Webhook
curl -X POST https://api.example.com/api/v1/webhooks \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://yourapp.com/webhooks/saas",
    "events": ["tenant.suspended", "ai.video.task.updated"],
    "is_active": true
  }'

# 重发某次投递
curl -X POST https://api.example.com/api/v1/webhooks/deliveries/1901.../resend \
  -H "Authorization: Bearer $TOKEN"
```

---

## 10. 开发者门户

```bash
# 创建 API Key
curl -X POST https://api.example.com/api/v1/developer/api-keys \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"生产密钥","abilities":["tenant:read","ai:text"]}'
```

---

## 错误响应

```json
{
  "success": false,
  "message": "权限不足",
  "error_code": "permission_denied"
}
```

| HTTP | error_code | 含义 |
|------|------------|------|
| 401 | `unauthenticated` | 未携带/无效 Token |
| 403 | `permission_denied` | RBAC 权限不足 |
| 403 | `ai_model_not_allowed` | 模型不在白名单 |
| 422 | `validation_failed` | 参数校验失败 |
| 429 | `ai_quota_exceeded` | 配额耗尽 |
| 429 | `rate_limited` | 限流 |
| 502 | `ai_provider_error` | 上游 AI 提供商错误 |
