<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Support\Wechat\WechatCrypto;

class WechatCryptoTest extends TestCase
{
    private string $token = 'test-token';

    private string $componentAppid = 'wxcomponent123456';

    private string $aesKeyB64;

    protected function setUp(): void
    {
        parent::setUp();

        // 43 字符 EncodingAESKey（补 '=' 后解出 32 字节），微信/企微同构
        $this->aesKeyB64 = substr(base64_encode(str_repeat('k', 32)), 0, 43);
    }

    private function crypto(?string $componentAppid = null): WechatCrypto
    {
        return new WechatCrypto($this->token, $this->aesKeyB64, $componentAppid ?? $this->componentAppid);
    }

    /**
     * 按微信协议加密：random(16B) + msg_len(4B 网络序) + msg + receiveid，PKCS7 块长 32
     */
    private function encrypt(string $msg, ?string $receiveId = null): string
    {
        $aesKey = base64_decode($this->aesKeyB64 . '=');
        $plain = random_bytes(16) . pack('N', strlen($msg)) . $msg . ($receiveId ?? $this->componentAppid);

        $pad = 32 - (strlen($plain) % 32);
        $plain .= str_repeat(chr($pad), $pad);

        return openssl_encrypt($plain, 'AES-256-CBC', $aesKey, OPENSSL_ZERO_PADDING, substr($aesKey, 0, 16));
    }

    private function sign(string $encrypt, string $timestamp = '1700000000', string $nonce = 'nonce1'): string
    {
        $parts = [$this->token, $timestamp, $nonce, $encrypt];
        sort($parts, SORT_STRING);

        return sha1(implode('', $parts));
    }

    public function test_decrypt_roundtrip(): void
    {
        $msg = '<xml><InfoType><![CDATA[component_verify_ticket]]></InfoType></xml>';

        $this->assertSame($msg, $this->crypto()->decrypt($this->encrypt($msg)));
    }

    public function test_decrypt_rejects_wrong_receive_id(): void
    {
        $encrypt = $this->encrypt('hello', 'other-appid');

        $this->assertNull($this->crypto()->decrypt($encrypt));
    }

    public function test_decrypt_skips_receive_id_check_when_appid_empty(): void
    {
        $encrypt = $this->encrypt('hello', 'other-appid');

        $this->assertSame('hello', $this->crypto('')->decrypt($encrypt));
    }

    public function test_decrypt_rejects_invalid_aes_key(): void
    {
        $crypto = new WechatCrypto($this->token, 'short-key', $this->componentAppid);

        $this->assertNull($crypto->decrypt($this->encrypt('hello')));
    }

    public function test_decrypt_rejects_invalid_base64_ciphertext(): void
    {
        $this->assertNull($this->crypto()->decrypt('not-valid-base64!!!'));
    }

    public function test_verify_signature_valid(): void
    {
        $encrypt = $this->encrypt('hello');

        $this->assertTrue(
            $this->crypto()->verifySignature($this->sign($encrypt), '1700000000', 'nonce1', $encrypt),
        );
    }

    public function test_verify_signature_invalid(): void
    {
        $encrypt = $this->encrypt('hello');

        $this->assertFalse(
            $this->crypto()->verifySignature('bad-signature', '1700000000', 'nonce1', $encrypt),
        );
        $this->assertFalse(
            $this->crypto()->verifySignature('', '1700000000', 'nonce1', $encrypt),
        );
    }

    public function test_verify_url_returns_plain_echostr(): void
    {
        $echostr = $this->encrypt('echo-plain-1024');

        $this->assertSame(
            'echo-plain-1024',
            $this->crypto()->verifyUrl($this->sign($echostr), '1700000000', 'nonce1', $echostr),
        );
    }

    public function test_verify_url_rejects_bad_signature(): void
    {
        $echostr = $this->encrypt('echo-plain-1024');

        $this->assertNull(
            $this->crypto()->verifyUrl('bad-signature', '1700000000', 'nonce1', $echostr),
        );
    }
}
