<?php

namespace MultiTenantSaas\Services;

use Illuminate\Http\Request;
use Yansongda\Pay\Pay;
use Yansongda\Pay\PayServiceProvider;

/**
 * 支付服务
 *
 * 集成 yansongda/pay，支持微信支付和支付宝
 *
 * 配置：config/pay.php
 * - wechat: 微信支付配置
 * - alipay: 支付宝配置
 */
class PayService
{
    /**
     * 微信支付 - JSAPI
     */
    public static function wechatJsapi(int $tenantId, float $amount, string $orderNo, string $openId): array
    {
        $order = [
            'out_trade_no' => $orderNo,
            'total_fee' => intval($amount * 100), // 转为分
            'body' => '积分充值',
            'openid' => $openId,
        ];

        return Pay::wechat()->jsapi($order)->toArray();
    }

    /**
     * 微信支付 - H5
     */
    public static function wechatH5(int $tenantId, float $amount, string $orderNo): array
    {
        $order = [
            'out_trade_no' => $orderNo,
            'total_fee' => intval($amount * 100),
            'body' => '积分充值',
        ];

        return Pay::wechat()->h5($order)->toArray();
    }

    /**
     * 支付宝 - 电脑网站
     */
    public static function alipayWeb(int $tenantId, float $amount, string $orderNo): string
    {
        $order = [
            'out_trade_no' => $orderNo,
            'total_amount' => $amount,
            'subject' => '积分充值',
        ];

        return Pay::alipay()->web($order)->getContent();
    }

    /**
     * 支付宝 - 手机网站
     */
    public static function alipayWap(int $tenantId, float $amount, string $orderNo): string
    {
        $order = [
            'out_trade_no' => $orderNo,
            'total_amount' => $amount,
            'subject' => '积分充值',
        ];

        return Pay::alipay()->wap($order)->getContent();
    }

    /**
     * 处理支付回调
     */
    public static function handleCallback(string $driver, Request $request): array
    {
        $pay = Pay::$driver();
        $result = $pay->callback($request->all());

        return [
            'trade_no' => $result->trade_no ?? '',
            'out_trade_no' => $result->out_trade_no ?? '',
            'total_amount' => $result->total_amount ?? 0,
            'status' => $result->trade_status ?? '',
        ];
    }

    /**
     * 查询订单
     */
    public static function query(string $driver, string $orderNo): array
    {
        $pay = Pay::$driver();
        $result = $pay->find(['out_trade_no' => $orderNo]);

        return $result->toArray();
    }

    /**
     * 退款
     */
    public static function refund(string $driver, string $orderNo, float $amount, string $refundNo): array
    {
        $order = [
            'out_trade_no' => $orderNo,
            'out_refund_no' => $refundNo,
            'total_fee' => intval($amount * 100),
            'refund_fee' => intval($amount * 100),
        ];

        $pay = Pay::$driver();
        $result = $pay->refund($order);

        return $result->toArray();
    }
}
