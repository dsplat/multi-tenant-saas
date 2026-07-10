<?php

declare(strict_types=1);

namespace MultiTenantSaas\EnterpriseWechat;

use RuntimeException;

class ArchiveDecryptor
{
    private string $aesKey;

    public function __construct(string $encodingAesKey)
    {
        if ($encodingAesKey === '') {
            throw new RuntimeException('encodingAesKey is required');
        }

        $this->aesKey = substr(base64_decode($encodingAesKey . '='), 0, 32);
    }

    public function decrypt(string $encrypted): string
    {
        $decoded = base64_decode($encrypted, true);

        if ($decoded === false || strlen($decoded) < 16) {
            return '';
        }

        $iv = substr($decoded, 0, 16);
        $ciphertext = substr($decoded, 16);

        $decrypted = openssl_decrypt($ciphertext, 'AES-256-CBC', $this->aesKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);

        if ($decrypted === false) {
            return '';
        }

        return $this->removePkcs7Padding($decrypted);
    }

    /**
     * @param  list<string>  $encryptedMessages
     * @return list<string>
     */
    public function batchDecrypt(array $encryptedMessages): array
    {
        $results = [];

        foreach ($encryptedMessages as $encrypted) {
            $decrypted = $this->decrypt($encrypted);

            if ($decrypted !== '') {
                $results[] = $decrypted;
            }
        }

        return $results;
    }

    /**
     * @param  string  $privateKeyPem  PEM 格式私钥
     */
    public function decryptRsaKey(string $encryptedKey, string $privateKeyPem): string
    {
        $privateKey = openssl_pkey_get_private($privateKeyPem);

        if ($privateKey === false) {
            throw new RuntimeException('Invalid RSA private key');
        }

        $decrypted = '';

        if (! openssl_private_decrypt(base64_decode($encryptedKey), $decrypted, $privateKey)) {
            throw new RuntimeException('RSA decryption failed');
        }

        return $decrypted;
    }

    private function removePkcs7Padding(string $data): string
    {
        $pad = ord(substr($data, -1));

        if ($pad < 1 || $pad > 32) {
            return $data;
        }

        return substr($data, 0, strlen($data) - $pad);
    }
}
