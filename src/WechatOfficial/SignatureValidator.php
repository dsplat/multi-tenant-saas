<?php

declare(strict_types=1);

namespace MultiTenantSaas\WechatOfficial;

class SignatureValidator
{
    public function __construct(
        protected string $token,
        protected string $encodingAesKey = '',
    ) {}

    public function validateSignature(array $params, string $signature): bool
    {
        $tmpArr = [$this->token, $params['timestamp'] ?? '', $params['nonce'] ?? ''];
        sort($tmpArr, SORT_STRING);
        $tmpStr = sha1(implode('', $tmpArr));

        return $tmpStr === $signature;
    }

    public function validateMsgSignature(array $params, string $msgSignature): bool
    {
        $encrypt = $params['encrypt'] ?? '';
        $tmpArr = [$this->token, $params['timestamp'] ?? '', $params['nonce'] ?? '', $encrypt];
        sort($tmpArr, SORT_STRING);
        $tmpStr = sha1(implode('', $tmpArr));

        return $tmpStr === $msgSignature;
    }

    public function decryptMessage(string $encrypted): string
    {
        $aesKey = base64_decode($this->encodingAesKey . '=');
        $iv = substr($aesKey, 0, 16);
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $aesKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);

        if ($decrypted === false) {
            return '';
        }

        $pad = ord(substr($decrypted, -1));
        if ($pad < 1 || $pad > 32) {
            $pad = 0;
        }

        return substr($decrypted, 0, strlen($decrypted) - $pad);
    }
}
