# TASK-001a: 支付服务认证与 Webhook 签名验证

**Sprint:** sprint-001
**状态:** READY
**预估时间:** 2 小时
**依赖:** 无（可与 TASK-001b / TASK-001c 并行）
**来源:** TASK-001 ESCALATE 拆分

---

## 目标

修复支付服务的认证方式错误和 Webhook 签名验证漏洞，消除 4 个 Critical 安全问题。

---

## 范围

**只允许修改：**
- `app/Services/StripeService.php`
- `app/Services/PayPalService.php`
- `app/Services/UnionPayService.php`

**禁止：**
- 修改其他任何文件
- 修改数据库 Schema
- 新增依赖包（UnionPay RSA 验签用 PHP 内置 openssl 扩展）

---

## 验收标准

- [ ] `StripeService.php:79,105,141` — `withBasicAuth()` 改为 `withToken()`（Bearer Token）
- [ ] `StripeService.php:201` — Webhook secret 未配置时抛出异常，不再跳过校验
- [ ] `PayPalService.php:199` — 新增 `verifyWebhookSignature()` 方法，调用 PayPal Webhook 验签 API
- [ ] `UnionPayService.php:243` — 用银联公钥证书实现真实 RSA 签名验证，不再返回 true

---

## 给 AI 的补充说明

- Stripe webhook secret 从 `config('services.stripe.webhook_secret')` 读取，为空时 `throw new \RuntimeException`
- PayPal 验签参考：https://developer.paypal.com/api/webhooks/v1/#webhooks_verify-webhook-signature
- UnionPay RSA 验签使用 `openssl_verify($data, $signature, $publicKey, OPENSSL_ALGO_SHA256)`
- 公钥证书路径从 `config('services.unionpay.public_cert')` 读取

---

## 状态流转记录

| 时间 | 状态 | 备注 |
|------|------|------|
| 2026-06-27 | READY | 从 TASK-001 ESCALATE 拆分 |
