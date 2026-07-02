<?php

declare(strict_types=1);

namespace MultiTenantSaas\EnterpriseWechat;

class SignatureValidator
{
    public function __construct(
        protected string $token,
        protected string $encodingAesKey = '',
    ) {}

    /**
     * 验证企业微信回调签名.
     */
    public function validateSignature(array $params, string $signature): bool
    {
        $tmpArr = [$this->token, $params['timestamp'] ?? '', $params['nonce'] ?? ''];
        sort($tmpArr, SORT_STRING);
        $tmpStr = implode('', $tmpArr);
        $tmpStr = sha1($tmpStr);

        return $tmpStr === $signature;
    }

    /**
     * 验证回调消息签名（含 encrypt 字段）.
     */
    public function validateMsgSignature(array $params, string $msgSignature): bool
    {
        $encrypt = $params['encrypt'] ?? '';
        $tmpArr = [$this->token, $params['timestamp'] ?? '', $params['nonce'] ?? '', $encrypt];
        sort($tmpArr, SORT_STRING);
        $tmpStr = implode('', $tmpArr);
        $tmpStr = sha1($tmpStr);

        return $tmpStr === $msgSignature;
    }

    /**
     * AES 解密消息.
     */
    public function decryptMessage(string $encrypted): string
    {
        $aesKey = base64_decode($this->encodingAesKey . '=');
        $iv = substr($aesKey, 0, 16);
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);

        if ($decrypted === false) {
            return '';
        }

        // 去除 PKCS7 padding
        $pad = ord(substr($decrypted, -1));
        if ($pad < 1 || $pad > 32) {
            $pad = 0;
        }

        return substr($decrypted, 0, strlen($decrypted) - $pad);
    }
}
