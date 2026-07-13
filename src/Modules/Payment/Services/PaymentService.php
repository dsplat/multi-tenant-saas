<?php

namespace MultiTenantSaas\Modules\Payment\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Models\CreditAccount;
use MultiTenantSaas\Models\PaymentOrder;

/**
 * 支付网关服务
 *
 * 独立支付网关对接层，框架核心支付由 PayService（yansongda/pay）提供
 * 本模块为可选的第三方支付网关适配
 *
 * 支付流程：
 * 1. 前端选择充值套餐 → POST /api/v1/pay/create
 * 2. 后端创建 payment_order，调用网关预下单
 * 3. 前端拿到支付参数（JSAPI）或跳转 URL（H5）
 * 4. 用户完成支付 → 网关 POST /api/v1/pay/notify
 * 5. 后端验签、幂等处理、到账积分
 */
class PaymentService
{
    private string $gateway;

    private string $serviceId;

    private string $password;

    private string $appId;

    private string $notifyUrl;

    private string $returnUrl;

    private string $showUrl;

    public function __construct()
    {
        $this->gateway = config('payment.gateway', 'https://pay.example.com');
        $this->serviceId = config('payment.service_id', '');
        $this->password = config('payment.password', '');
        $this->appId = config('payment.app_id', '');
        $this->notifyUrl = config('payment.notify_url', '');
        $this->returnUrl = config('payment.return_url', '');
        $this->showUrl = config('payment.show_url', '');
    }

    /**
     * 签名算法
     * null 值以字符串 "null" 参与签名，不能过滤
     */
    public function sign(array $params): string
    {
        ksort($params);
        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = $k . '=' . ($v === null ? 'null' : $v);
        }

        return strtoupper(md5(implode('&', $parts) . '&key=' . $this->password));
    }

    /**
     * 创建支付订单并调用 H5 预下单
     *
     * @param  int  $credits  充值积分数
     * @param  int  $priceFen  金额（分）
     * @param  string  $openid  微信 openid（JSAPI 必填）
     */
    /**
     * 创建支付订单
     *
     * @param  int  $credits  充值积分数
     * @param  int  $priceFen  金额（分）
     * @param  string|null  $openid  微信 openid（JSAPI 必填）
     * @param  string|null  $tradeType  交易类型：JSAPI / MWEB / NATIVE，null 则自动判断
     */
    public function createOrder(int $userId, int $credits, int $priceFen, ?string $openid = null, ?string $tradeType = null): PaymentOrder
    {
        $tradeNo = PaymentOrder::generateTradeNo();

        // 自动判断交易类型
        if (! $tradeType) {
            $tradeType = $openid ? 'JSAPI' : 'MWEB';
        }

        $order = PaymentOrder::create([
            'out_trade_no' => $tradeNo,
            'user_id' => $userId,
            'subject' => "积分充值 {$credits} 积分",
            'pay_body' => "积分充值-{$credits}积分",
            'total_fee' => $priceFen,
            'credits' => $credits,
            'trade_type' => $tradeType,
            'openid' => $openid,
            'status' => 'pending',
        ]);

        // 调用预下单
        $payData = match ($tradeType) {
            'JSAPI' => $this->jsapiPrepay($order),
            'NATIVE' => $this->nativePrepay($order),
            default => $this->h5Prepay($order),
        };

        $order->update(['pay_data' => json_encode($payData)]);

        return $order;
    }

    /**
     * H5 预下单（微信外部浏览器）
     *
     * 签名参数用 body，POST 参数用 pay_body
     * null 值以 "null" 字符串参与签名
     */
    private function h5Prepay(PaymentOrder $order): array
    {
        $ts = (string) time();

        // 签名参数（含 body，不含 app_id，null 以 "null" 参与签名）
        $signParams = [
            'out_trade_no' => $order->out_trade_no,
            'subject' => $order->subject,
            'total_fee' => (string) $order->total_fee,
            'service_id' => $this->serviceId,
            'body' => $order->pay_body,
            'show_url' => $this->showUrl,
            'return_url' => $this->returnUrl,
            'attach' => null,
            'timestamp' => $ts,
            'notify_url' => $this->notifyUrl ?: null,
        ];
        $sign = $this->sign($signParams);

        // POST 参数（含 pay_body，不含 body）
        $params = [
            'out_trade_no' => $order->out_trade_no,
            'subject' => $order->subject,
            'total_fee' => (int) $order->total_fee,
            'service_id' => $this->serviceId,
            'pay_body' => $order->pay_body,
            'show_url' => $this->showUrl,
            'return_url' => $this->returnUrl,
            'client_ip' => request()->ip() ?? '127.0.0.1',
            'timestamp' => $ts,
            'sign' => $sign,
            'notify_url' => $this->notifyUrl,
            'attach' => null,
        ];

        Log::info('MantouPay H5 prepay request', ['trade_no' => $order->out_trade_no, 'amount' => $order->total_fee]);

        $resp = Http::asForm()
            ->post($this->gateway . '/v3/wechat/h5/prepay', $params)
            ->json();

        Log::info('MantouPay H5 prepay response', ['trade_no' => $order->out_trade_no, 'resp' => $resp]);

        if (! ($resp['status'] ?? false)) {
            throw new \RuntimeException(trans('payment.h5_preorder_failed') . ': ' . ($resp['message'] ?? trans('common.unknown_error')));
        }

        return $resp['data'];
    }

    /**
     * Native 预下单（PC 扫码支付）
     *
     * 返回 code_url 供前端生成二维码
     */
    private function nativePrepay(PaymentOrder $order): array
    {
        $ts = (string) time();

        // 签名参数（含 body，不含 app_id，null 以 "null" 参与签名）
        $signParams = [
            'out_trade_no' => $order->out_trade_no,
            'subject' => $order->subject,
            'total_fee' => (string) $order->total_fee,
            'service_id' => $this->serviceId,
            'body' => $order->pay_body,
            'show_url' => $this->showUrl,
            'client_ip' => request()->ip() ?? '127.0.0.1',
            'return_url' => $this->returnUrl,
            'attach' => null,
            'timestamp' => $ts,
            'notify_url' => $this->notifyUrl ?: null,
        ];
        $sign = $this->sign($signParams);

        // POST 参数（含 pay_body，不含 body）
        $params = [
            'out_trade_no' => $order->out_trade_no,
            'subject' => $order->subject,
            'total_fee' => (int) $order->total_fee,
            'service_id' => $this->serviceId,
            'pay_body' => $order->pay_body,
            'show_url' => $this->showUrl,
            'client_ip' => request()->ip() ?? '127.0.0.1',
            'return_url' => $this->returnUrl,
            'sign' => $sign,
            'notify_url' => $this->notifyUrl,
            'attach' => null,
            'timestamp' => $ts,
        ];

        Log::info('MantouPay Native prepay request', ['trade_no' => $order->out_trade_no, 'amount' => $order->total_fee]);

        $resp = Http::asForm()
            ->post($this->gateway . '/v3/wechat/nativepay', $params)
            ->json();

        Log::info('MantouPay Native prepay response', ['trade_no' => $order->out_trade_no, 'resp' => $resp]);

        if (! ($resp['status'] ?? false)) {
            throw new \RuntimeException(trans('payment.native_preorder_failed') . ': ' . ($resp['message'] ?? trans('common.unknown_error')));
        }

        return $resp['data'];
    }

    /**
     * JSAPI 预下单（微信公众号内）
     *
     * 签名参数用 body，POST 参数用 pay_body
     */
    private function jsapiPrepay(PaymentOrder $order): array
    {
        // 签名参数（含 body，不含 pay_body）
        $signParams = [
            'app_id' => $this->appId,
            'out_trade_no' => $order->out_trade_no,
            'subject' => $order->subject,
            'total_fee' => (string) $order->total_fee,
            'service_id' => $this->serviceId,
            'body' => $order->pay_body,
            'show_url' => $this->showUrl,
            'return_url' => $this->returnUrl,
            'openid' => $order->openid,
            'attach' => null,
            'notify_url' => $this->notifyUrl ?: null,
        ];
        $sign = $this->sign($signParams);

        // POST 参数（含 pay_body，不含 body）
        $params = [
            'app_id' => $this->appId,
            'out_trade_no' => $order->out_trade_no,
            'subject' => $order->subject,
            'total_fee' => (int) $order->total_fee,
            'service_id' => $this->serviceId,
            'pay_body' => $order->pay_body,
            'show_url' => $this->showUrl,
            'return_url' => $this->returnUrl,
            'openid' => $order->openid,
            'sign' => $sign,
            'notify_url' => $this->notifyUrl,
            'attach' => null,
        ];

        Log::info('MantouPay JSAPI prepay request', ['trade_no' => $order->out_trade_no, 'amount' => $order->total_fee]);

        $resp = Http::asForm()
            ->post($this->gateway . '/v3/wechat/prepay', $params)
            ->json();

        Log::info('MantouPay JSAPI prepay response', ['trade_no' => $order->out_trade_no, 'resp' => $resp]);

        if (! ($resp['status'] ?? false)) {
            throw new \RuntimeException(trans('payment.jsapi_preorder_failed') . ': ' . ($resp['message'] ?? trans('common.unknown_error')));
        }

        return $resp['data'];
    }

    /**
     * 处理支付回调（验签 + 幂等 + 到账）
     *
     * @param  string  $xml  原始 XML
     * @return bool 是否处理成功
     */
    public function handleNotify(string $xml): bool
    {
        try {
            $data = $this->parseAndVerifyNotify($xml);
        } catch (\Exception $e) {
            Log::error('MantouPay notify verify failed', ['error' => $e->getMessage()]);

            return false;
        }

        $tradeNo = $data['out_trade_no'] ?? '';
        $transactionId = $data['transaction_id'] ?? '';
        $totalFee = (int) ($data['total_fee'] ?? 0);

        $order = PaymentOrder::where('out_trade_no', $tradeNo)->first();
        if (! $order) {
            Log::warning('MantouPay notify: order not found', ['trade_no' => $tradeNo]);

            return false;
        }

        // 幂等：已支付的订单不再处理
        if ($order->isPaid()) {
            Log::info('MantouPay notify: already paid', ['trade_no' => $tradeNo]);

            return true;
        }

        // 金额校验
        if ($totalFee !== $order->total_fee) {
            Log::error('MantouPay notify: amount mismatch', [
                'trade_no' => $tradeNo,
                'expected' => $order->total_fee,
                'actual' => $totalFee,
            ]);

            return false;
        }

        // 更新订单状态
        $order->update([
            'status' => 'paid',
            'transaction_id' => $transactionId,
            'paid_at' => now(),
        ]);

        // 到账积分
        $this->creditUserAccount($order);

        Log::info('MantouPay notify: payment success', [
            'trade_no' => $tradeNo,
            'credits' => $order->credits,
            'user_id' => $order->user_id,
        ]);

        return true;
    }

    /**
     * 解析并验签回调 XML
     */
    private function parseAndVerifyNotify(string $xml): array
    {
        $data = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        $params = json_decode(json_encode($data), true);

        $sign = $params['sign'] ?? '';
        unset($params['sign']);

        ksort($params);
        $str = urldecode(http_build_query($params)) . '&key=' . $this->password;
        $localSign = strtoupper(md5($str));

        if ($sign !== $localSign) {
            throw new \RuntimeException(trans('payment.signature_invalid'));
        }

        return $params;
    }

    /**
     * 到账积分到用户账户
     */
    private function creditUserAccount(PaymentOrder $order): void
    {
        $account = CreditAccount::firstOrCreate(
            [
                'user_id' => $order->user_id,
                'tenant_id' => $order->tenant_id,
                'account_type' => 'personal',
            ],
            [
                'balance' => 0,
                'gift_balance' => 0,
                'recharge_balance' => 0,
                'total_recharged' => 0,
                'total_consumed' => 0,
                'status' => 'active',
            ]
        );

        $account->recharge(
            $order->user_id,
            $order->credits,
            "微信充值 {$order->credits} 积分",
            [
                'source' => 'wechat_pay',
                'out_trade_no' => $order->out_trade_no,
                'total_fee' => $order->total_fee,
            ]
        );

        Log::info('MantouPay: credited user account', [
            'user_id' => $order->user_id,
            'credits' => $order->credits,
            'balance_after' => $account->fresh()->balance,
        ]);
    }

    /**
     * 查询订单状态
     */
    public function queryOrder(string $tradeNo): ?array
    {
        $resp = Http::get($this->gateway . '/check/order', [
            'out_trade_no' => $tradeNo,
        ])->json();

        return $resp ?: null;
    }

    /**
     * 获取充值套餐列表
     */
    public function getPackages(): array
    {
        return config('mantou_pay.packages', []);
    }
}
