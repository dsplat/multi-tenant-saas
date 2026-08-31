<?php

namespace MultiTenantSaas\Support\Wechat;

/**
 * 微信公众号/第三方平台回调加解密（共享 SDK 层，自包含实现）
 *
 * 官方协议（与企微同构）：AESKey = Base64Decode(EncodingAESKey . '=')，
 * AES-256-CBC，明文结构 = random(16B) + msg_len(4B 网络序) + msg + receiveid。
 * receiveid 为第三方平台 component_appid（公众号消息场景为原始 ID，此处
 * 仅第三方平台回调使用，配置了 component_appid 才校验）。
 *
 * 注意：微信回调解密块长为 32（AES-256），PKCS7 填充最大 32 字节。
 */
class WechatCrypto
{
    public function __construct(
        private readonly string $token,
        private readonly string $encodingAesKey,
        private readonly string $componentAppid = '',
    ) {}

    /**
     * 验证回调签名（sha1(sort(token, timestamp, nonce, encrypt))）
     *
     * encrypt 传空串时等价于无密文的 3 元验签（空串不影响排序拼接结果）。
     */
    public function verifySignature(string $signature, string $timestamp, string $nonce, string $encrypt): bool
    {
        if ($this->token === '' || $signature === '') {
            return false;
        }

        $parts = [$this->token, $timestamp, $nonce, $encrypt];
        sort($parts, SORT_STRING);

        return hash_equals(sha1(implode('', $parts)), $signature);
    }

    /**
     * URL 有效性验证（GET echostr）：验签 + 解密，成功返回明文 echostr
     */
    public function verifyUrl(string $signature, string $timestamp, string $nonce, string $echostr): ?string
    {
        if (! $this->verifySignature($signature, $timestamp, $nonce, $echostr)) {
            return null;
        }

        return $this->decrypt($echostr);
    }

    /**
     * 解密回调密文，返回明文 msg（结构校验失败返回 null）
     */
    public function decrypt(string $encrypt): ?string
    {
        $aesKey = base64_decode($this->encodingAesKey . '=', true);

        if ($aesKey === false || strlen($aesKey) !== 32) {
            return null;
        }

        $decrypted = openssl_decrypt(
            $encrypt,
            'AES-256-CBC',
            $aesKey,
            OPENSSL_ZERO_PADDING,
            substr($aesKey, 0, 16),
        );

        if ($decrypted === false) {
            return null;
        }

        // 去除 PKCS7 padding（微信块长 32）
        $pad = ord(substr($decrypted, -1));
        if ($pad < 1 || $pad > 32) {
            return null;
        }
        $decrypted = substr($decrypted, 0, -$pad);

        // random(16B) + msg_len(4B) + msg + receiveid
        if (strlen($decrypted) < 20) {
            return null;
        }

        $msgLen = unpack('N', substr($decrypted, 16, 4))[1] ?? 0;

        if ($msgLen < 0 || 20 + $msgLen > strlen($decrypted)) {
            return null;
        }

        $msg = substr($decrypted, 20, $msgLen);
        $receiveId = substr($decrypted, 20 + $msgLen);

        // receiveid 应为第三方平台 appid（配置了才校验）
        if ($this->componentAppid !== '' && $receiveId !== $this->componentAppid) {
            return null;
        }

        return $msg;
    }
}
