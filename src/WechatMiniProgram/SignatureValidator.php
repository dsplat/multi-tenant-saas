<?php

declare(strict_types=1);

namespace MultiTenantSaas\WechatMiniProgram;

/**
 * 微信小程序签名验证器.
 *
 * 签名算法与企业微信一致：SHA1(sort([token, timestamp, nonce])).
 */
class SignatureValidator
{
    public function __construct(
        protected string $token,
    ) {}

    /**
     * 验证回调请求签名.
     */
    public function validateSignature(array $params, string $signature): bool
    {
        $tmpArr = [$this->token, $params['timestamp'] ?? '', $params['nonce'] ?? ''];
        sort($tmpArr, SORT_STRING);
        $tmpStr = implode('', $tmpArr);
        $tmpStr = sha1($tmpStr);

        return $tmpStr === $signature;
    }
}
