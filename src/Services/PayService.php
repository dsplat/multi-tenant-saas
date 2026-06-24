<?php

namespace MultiTenantSaas\Services;

use Illuminate\Http\Request;
use MultiTenantSaas\Models\TenantSetting;
use Yansongda\Pay\Pay;

/**
 * 支付服务（租户级配置）
 *
 * 每个租户独立配置微信/支付宝商户号
 * 配置存储在 tenant_settings 表，group = 'payment'
 */
class PayService
{
    /**
     * 获取租户支付配置
     */
    protected static function getConfig(int $tenantId, string $driver): array
    {
        $group = 'payment';

        if ($driver === 'wechat') {
            return [
                'app_id' => TenantSetting::get($tenantId, $group, 'wechat_app_id', ''),
                'mch_id' => TenantSetting::get($tenantId, $group, 'wechat_mch_id', ''),
                'notify_url' => TenantSetting::get($tenantId, $group, 'wechat_notify_url', ''),
                'serial_no' => TenantSetting::get($tenantId, $group, 'wechat_serial_no', ''),
                'private_key' => TenantSetting::get($tenantId, $group, 'wechat_private_key', ''),
                'public_key_path' => TenantSetting::get($tenantId, $group, 'wechat_public_key_path', ''),
            ];
        }

        if ($driver === 'alipay') {
            return [
                'app_id' => TenantSetting::get($tenantId, $group, 'alipay_app_id', ''),
                'notify_url' => TenantSetting::get($tenantId, $group, 'alipay_notify_url', ''),
                'return_url' => TenantSetting::get($tenantId, $group, 'alipay_return_url', ''),
                'ali_public_key' => TenantSetting::get($tenantId, $group, 'alipay_public_key', ''),
                'private_key' => TenantSetting::get($tenantId, $group, 'alipay_private_key', ''),
                'mode' => TenantSetting::get($tenantId, $group, 'alipay_mode', 'normal'),
            ];
        }

        return [];
    }

    /**
     * 动态创建 Pay 实例（租户级）
     */
    protected static function createPayInstance(int $tenantId, string $driver): Pay
    {
        $config = self::getConfig($tenantId, $driver);

        // 过滤空值
        $config = array_filter($config, fn($v) => $v !== '' && $v !== null);

        if (empty($config)) {
            throw new \RuntimeException("租户 {$tenantId} 未配置 {$driver} 支付");
        }

        return Pay::$driver($config);
    }

    /**
     * 公开的 Pay 实例创建方法（供 RefundService 等使用）
     */
    public static function createPayInstancePublic(int $tenantId, string $driver): Pay
    {
        return self::createPayInstance($tenantId, $driver);
    }

    /**
     * 微信支付 - JSAPI
     */
    public static function wechatJsapi(int $tenantId, float $amount, string $orderNo, string $openId): array
    {
        $order = [
            'out_trade_no' => $orderNo,
            'total_fee' => intval($amount * 100),
            'body' => '积分充值',
            'openid' => $openId,
        ];

        return self::createPayInstance($tenantId, 'wechat')->jsapi($order)->toArray();
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

        return self::createPayInstance($tenantId, 'wechat')->h5($order)->toArray();
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

        return self::createPayInstance($tenantId, 'alipay')->web($order)->getContent();
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

        return self::createPayInstance($tenantId, 'alipay')->wap($order)->getContent();
    }

    /**
     * 处理支付回调（带验签）
     *
     * 支付回调路由无需认证，但需要：
     * 1. 从 URL 参数或请求体获取 tenant_id
     * 2. 使用租户配置验证签名
     */
    public static function handleCallback(string $driver, Request $request): array
    {
        // 从 URL 参数获取 tenant_id
        $tenantId = $request->query('tenant_id');

        if (!$tenantId) {
            throw new \RuntimeException('支付回调缺少 tenant_id 参数');
        }

        // 使用租户配置创建 Pay 实例（包含验签）
        $pay = self::createPayInstance((int) $tenantId, $driver);
        $result = $pay->callback($request->all());

        return [
            'tenant_id' => $tenantId,
            'trade_no' => $result->trade_no ?? '',
            'out_trade_no' => $result->out_trade_no ?? '',
            'total_amount' => $result->total_amount ?? 0,
            'status' => $result->trade_status ?? '',
        ];
    }

    /**
     * 检查租户是否已配置支付
     */
    public static function isConfigured(int $tenantId, string $driver): bool
    {
        $config = self::getConfig($tenantId, $driver);
        return !empty(array_filter($config, fn($v) => $v !== '' && $v !== null));
    }

    /**
     * 获取租户支付配置（用于后台展示）
     */
    public static function getPaymentConfig(int $tenantId): array
    {
        return [
            'wechat' => [
                'configured' => self::isConfigured($tenantId, 'wechat'),
                'app_id' => TenantSetting::get($tenantId, 'payment', 'wechat_app_id', ''),
                'mch_id' => TenantSetting::get($tenantId, 'payment', 'wechat_mch_id', ''),
            ],
            'alipay' => [
                'configured' => self::isConfigured($tenantId, 'alipay'),
                'app_id' => TenantSetting::get($tenantId, 'payment', 'alipay_app_id', ''),
            ],
        ];
    }

    /**
     * 更新租户支付配置
     */
    public static function updatePaymentConfig(int $tenantId, string $driver, array $config): void
    {
        $prefix = $driver === 'wechat' ? 'wechat' : 'alipay';
        $sensitiveKeys = ['private_key', 'public_key_path', 'secret'];

        foreach ($config as $key => $value) {
            if (in_array($key, $sensitiveKeys) && $value === '********') {
                continue; // 跳过遮罩占位符
            }
            $isEncrypted = in_array($key, $sensitiveKeys);
            TenantSetting::set($tenantId, 'payment', "{$prefix}_{$key}", $value, $isEncrypted);
        }
    }
}
