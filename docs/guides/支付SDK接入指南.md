# 支付 SDK 接入指南

> TASK-001 支付 SDK 扩展文档  
> 框架版本: v1.1.0  
> 最后更新: 2026-06-27

---

## 概览

框架支持 5 种支付驱动：

| 驱动 | 支付方式 | Service |
|------|---------|---------|
| `wechat` | JSAPI / H5 / Native / App / Miniapp | `PayService` |
| `alipay` | Web / Wap / App / Miniapp / Pos（当面付） | `PayService` |
| `paypal` | Checkout Orders（REST v2） | `PayPalService` |
| `stripe` | Checkout Sessions / Payment Intents | `StripeService` |
| `unionpay` | 网关支付 | `UnionPayService` |

安全增强：支付密码、支付限额、支付风控、支付日志、支付对账 — `PaymentSecurityService`

---

## 配置

### 1. 全局默认配置（`config/pay.php`）

```php
'wechat' => [
    'default' => [
        'app_id' => env('WECHAT_PAY_APP_ID', ''),
        'mch_id' => env('WECHAT_PAY_MCH_ID', ''),
        // ...
    ],
],

'paypal' => [
    'client_id' => env('PAYPAL_CLIENT_ID', ''),
    'client_secret' => env('PAYPAL_CLIENT_SECRET', ''),
    'mode' => env('PAYPAL_MODE', 'sandbox'),
    // ...
],
```

### 2. 租户级配置（动态覆盖）

```php
use MultiTenantSaas\Services\PayService;

PayService::updatePaymentConfig($tenantId, 'wechat', [
    'app_id' => 'wx...',
    'mch_id' => '16...',
    'private_key' => '...',  // 加密存储
    'serial_no' => '...',
]);
```

### 3. 安全配置

```php
'security' => [
    'payment_password_enabled' => false,
    'per_payment_limit' => 0,      // 单笔限额（元）
    'daily_payment_limit' => 0,     // 日累计限额（元）
    'risk_failure_threshold' => 5,  // 1 小时失败阈值
    'risk_cooldown_sec' => 1800,    // 冷却时间
],

'refund' => [
    'partial_refund_enabled' => true,
    'reason_required' => true,
    'reason_min_length' => 5,
    'reason_max_length' => 200,
],
```

---

## 微信支付

### JSAPI（公众号内）

```php
$params = PayService::wechatJsapi($tenantId, $amount, $orderNo, $openId, '订单主题');
// 返回: ['appId' => '...', 'timeStamp' => '...', 'paySign' => '...']
```

### Native（PC 扫码）

```php
$params = PayService::wechatNative($tenantId, $amount, $orderNo, '订单主题');
// 返回: ['code_url' => 'weixin://wxpay/bizpayurl?pr=...']
```

### H5（外部浏览器）

```php
$params = PayService::wechatH5($tenantId, $amount, $orderNo, '订单主题');
// 返回: ['h5_url' => 'https://wx.tenpay.com/cgi-bin/mmpayweb-bin/checkmweb?...']
```

### App 支付

```php
$params = PayService::wechatApp($tenantId, $amount, $orderNo, '订单主题');
// 返回 App SDK 调起所需参数
```

### 小程序支付

```php
$params = PayService::wechatMiniapp($tenantId, $amount, $orderNo, $openId, '订单主题');
```

---

## 支付宝

### 电脑网站支付（Web）

```php
$formHtml = PayService::alipayWeb($tenantId, $amount, $orderNo, '订单主题');
// 返回: 自动提交的 HTML 表单
```

### 手机网站支付（Wap）

```php
$formHtml = PayService::alipayWap($tenantId, $amount, $orderNo, '订单主题');
```

### App 支付

```php
$params = PayService::alipayApp($tenantId, $amount, $orderNo, '订单主题');
```

### 小程序支付

```php
$params = PayService::alipayMiniapp($tenantId, $amount, $orderNo, $buyerId, '订单主题');
```

### 当面付（扫码/条码）

```php
$params = PayService::alipayPos($tenantId, $amount, $orderNo, $authCode, '订单主题');
// $authCode: 用户付款码（28 开头的 25-30 位数字）
```

---

## PayPal

### 创建订单

```php
use MultiTenantSaas\Services\PayPalService;

$paypal = app(PayPalService::class);

$result = $paypal->createOrder($tenantId, $amount, $orderNo, 'Order description');
// 返回: ['paypal_order_id' => '...', 'approval_url' => 'https://www.paypal.com/checkoutnow?...']
```

用户在 PayPal 授权后跳回 `return_url`，后端调用 capture：

```php
$result = $paypal->captureOrder($tenantId, $paypalOrderId);
// 返回: ['status' => 'paid', 'transaction_id' => '...', ...]
```

### 退款

```php
$result = $paypal->refund($tenantId, $captureId, $partialAmount);
// $partialAmount = 0 表示全额退款
```

### Webhook

```php
$result = $paypal->handleWebhook($tenantId, $payload);
// 返回: ['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'order_no' => '...', 'status' => 'paid']
```

---

## Stripe

### Checkout Session

```php
use MultiTenantSaas\Services\StripeService;

$stripe = app(StripeService::class);

$result = $stripe->createCheckoutSession($tenantId, $amount, $orderNo, '描述', 'CNY');
// 返回: ['session_id' => 'cs_test_...', 'session_url' => 'https://checkout.stripe.com/...']
```

### Payment Intent

```php
$result = $stripe->createPaymentIntent($tenantId, $amount, 'CNY');
// 返回: ['client_secret' => '...', 'payment_intent_id' => 'pi_...']
```

### 退款

```php
$result = $stripe->refund($tenantId, $paymentIntentId, $partialAmount);
```

### Webhook

```php
$result = $stripe->handleWebhook($tenantId, $payload, $signatureHeader);
// 自动验签
```

---

## 银联支付

### 创建订单

```php
use MultiTenantSaas\Services\UnionPayService;

$unionpay = app(UnionPayService::class);

$result = $unionpay->createOrder($tenantId, $amount, $orderNo, '订单描述');
// 返回: ['params' => [...], 'gateway_url' => 'https://gateway.test.95516.com/...']
// 前端通过 form POST 自动跳转到银联页面
```

### 查询订单

```php
$result = $unionpay->queryOrder($tenantId, $orderNo, $txnTime);
```

### 退款

```php
$result = $unionpay->refund($tenantId, $orderNo, $queryId, $txnTime, $partialAmount);
```

### 异步通知

```php
$result = $unionpay->handleNotify($tenantId, $payload);
```

> **注意**：银联签名需使用证书，生产部署请确保 `cert_path` 配置正确。框架提供了 `sign()` / `verifySignature()` 占位实现，真实部署时需使用银联官方 SDK。

---

## 支付安全

### 支付密码

```php
use MultiTenantSaas\Services\PaymentSecurityService;

$security = app(PaymentSecurityService::class);

// 设置
$security->setPaymentPassword($userId, '123456');

// 验证
$valid = $security->verifyPaymentPassword($userId, '123456');
```

### 限额检查

```php
// 单笔限额
$ok = $security->checkPerPaymentLimit($amount);

// 日累计限额
$ok = $security->checkDailyLimit($userId, $amount);
```

### 风控检查

```php
$check = $security->checkRisk($userId);
// ['allowed' => true, 'reason' => null, 'retry_after_sec' => 0]
// ['allowed' => false, 'reason' => '风险控制：失败次数过多', 'retry_after_sec' => 1800]
```

### 记录支付日志

```php
$security->logPaymentAttempt($userId, $orderNo, $amount, 'failed', [
    'driver' => 'wechat',
    'error' => '签名错误',
]);
```

### 支付对账

```php
$result = $security->reconcileOrder($tenantId, $orderNo, $gatewayAmount);
// ['match' => true, 'framework_amount' => 100.00, 'gateway_amount' => 100.00]
```

### 支付报表

```php
$report = $security->dailyReport($tenantId, '2026-06-01', '2026-06-30');
// 按日聚合：date / driver / status / count / total
```

---

## 退款

### 全额退款

```php
use MultiTenantSaas\Services\RefundService;

$result = RefundService::refund($tenantId, $orderNo, $fullAmount, '商品缺货退款');
// ['refund_no' => 'RFD...', 'status' => 'refunding', ...]
```

### 部分退款

```php
$result = RefundService::refund($tenantId, $orderNo, $partialAmount, '部分退款理由');
```

### 查询退款状态

```php
$result = RefundService::queryRefundStatus($tenantId, $orderNo);
```

### 退款理由管理

- `config('pay.refund.reason_required')` — 是否必填
- `config('pay.refund.reason_min_length')` — 最小长度（默认 5）
- `config('pay.refund.reason_max_length')` — 最大长度（默认 200）

---

## 回调处理

### 微信/支付宝回调

```php
public function payCallback(Request $request, string $driver)
{
    $result = PayService::handleCallback($driver, $request);
    // ['tenant_id' => '...', 'trade_no' => '...', 'out_trade_no' => '...', ...]
}
```

### 退款回调

```php
$result = RefundService::handleRefundCallback($driver, $request);
```

> 回调路由需附加 `?tenant_id=` 参数以识别租户配置。

---

## 配置管理

### 验证配置

```php
$result = PayService::validateConfig($tenantId, 'wechat');
// ['valid' => true, 'message' => '配置有效']
```

### 测试连接

```php
$result = PayService::testConnection($tenantId, 'wechat');
// ['success' => true, 'message' => '连接成功']
```

### 导入/导出

```php
$config = PayService::exportPaymentConfig($tenantId); // 敏感字段掩码
PayService::updatePaymentConfig($tenantId, 'paypal', $config['paypal']);
```

---

## 常见问题

### Q: 如何添加新的支付驱动？

1. 在 `config/pay.php` 中添加驱动配置
2. 在 `PayService::getConfig()` 中添加驱动分支
3. 在 `PayService::getPaymentConfig()` 中添加展示字段
4. 创建独立的 Service 类（如 `PayPalService`）
5. 在 `TenancyServiceProvider` 中注册

### Q: 如何切换沙箱/生产环境？

```php
// PayPal
PayService::updatePaymentConfig($tenantId, 'paypal', ['mode' => 'live']);

// Stripe
PayService::updatePaymentConfig($tenantId, 'stripe', ['mode' => 'live']);

// 银联
PayService::updatePaymentConfig($tenantId, 'unionpay', ['mode' => 'production']);
```

### Q: 退款失败如何处理？

1. 检查 `RefundService::queryRefundStatus()` 获取网关返回状态
2. 检查 `FinancialRecord` 表的 `status` 字段
3. 若网关返回失败，联系支付平台客服
4. 若框架侧状态与网关不一致，使用 `PaymentSecurityService::reconcileOrder()` 对账
