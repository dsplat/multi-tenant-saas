<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantKey;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 租户加密密钥服务
 *
 * 职责：
 * - 为每个租户生成独立的 AES-256 加密密钥
 * - 使用系统主密钥（APP_MASTER_KEY）加密租户密钥后存储（信封加密）
 * - 支持密钥轮换（re-encrypt 已有数据）
 * - 支持 BYOK（Bring Your Own Key）
 *
 * 注意：本服务按显式 tenant_id 操作，绕过 TenantScope，
 * 安全由调用方（Controller/Service）保证用户有权访问目标租户。
 */
class TenantKeyService
{
    private const KEY_LENGTH_BYTES = 32;

    private const IV_LENGTH_BYTES = 16;

    /**
     * 生成租户的初始 AES-256 密钥
     *
     * @throws \RuntimeException 该租户已存在活跃密钥时抛出
     */
    public function generateKey(int $tenantId): TenantKey
    {
        if ($this->getActiveKey($tenantId) !== null) {
            throw new \RuntimeException(trans('tenant.key_already_exists'));
        }

        $plain = random_bytes(self::KEY_LENGTH_BYTES);

        return $this->createKeyRecord($tenantId, $plain, 'system', 'active');
    }

    /**
     * 导入 BYOK 密钥（Bring Your Own Key）
     *
     * @param  string  $providedKey  32 字节密钥，支持原始二进制、base64 或 hex 编码
     *
     * @throws \RuntimeException 已存在活跃密钥或密钥格式无效时抛出
     */
    public function importByok(int $tenantId, string $providedKey): TenantKey
    {
        if ($this->getActiveKey($tenantId) !== null) {
            throw new \RuntimeException(trans('tenant.key_already_exists'));
        }

        $plain = $this->normalizeByokKey($providedKey);

        return $this->createKeyRecord($tenantId, $plain, 'byok', 'active');
    }

    /**
     * 获取租户当前活跃密钥
     */
    public function getActiveKey(int $tenantId): ?TenantKey
    {
        return TenantKey::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * 解密存储的租户密钥，返回明文 AES 密钥
     */
    public function decryptKey(TenantKey $key): string
    {
        return $this->decryptWithMasterKey($key->encrypted_key);
    }

    /**
     * 使用租户当前活跃密钥加密应用数据
     *
     * @throws \RuntimeException 租户无活跃密钥时抛出
     */
    public function encryptData(int $tenantId, string $plaintext): string
    {
        $key = $this->getActiveKey($tenantId);
        if ($key === null) {
            throw new \RuntimeException(trans('tenant.key_not_found'));
        }

        return $this->encryptWithKey($plaintext, $this->decryptKey($key));
    }

    /**
     * 解密应用数据
     *
     * 轮换过渡期内会依次尝试活跃密钥与已退役密钥。
     *
     * @throws \RuntimeException 无法解密时抛出
     */
    public function decryptData(string $payload, int $tenantId): string
    {
        $keys = TenantKey::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'retired'])
            ->orderBy('created_at', 'desc')
            ->get();

        /** @var TenantKey $key */
        foreach ($keys as $key) {
            try {
                return $this->decryptWithKey($payload, $this->decryptKey($key));
            } catch (\Exception $e) {
                continue;
            }
        }

        throw new \RuntimeException(trans('tenant.key_decrypt_failed'));
    }

    /**
     * 轮换租户密钥
     *
     * 生成新密钥并将旧密钥标记为 retired，随后对配置的字段进行 re-encrypt。
     * 密钥轮换是 CPU 密集型操作，生产环境应通过 rotation_queue 配置异步执行。
     *
     * @param  array<int, array{table: string, column: string, id_column?: string, tenant_column?: string}>  $fieldsToReEncrypt
     *
     * @throws \RuntimeException 租户无活跃密钥时抛出
     */
    public function rotateKey(int $tenantId, array $fieldsToReEncrypt = []): TenantKey
    {
        $oldKey = $this->getActiveKey($tenantId);
        if ($oldKey === null) {
            throw new \RuntimeException(trans('tenant.key_not_found'));
        }

        DB::beginTransaction();
        try {
            $oldKey->update(['status' => 'retired', 'rotated_at' => now()]);

            $plain = random_bytes(self::KEY_LENGTH_BYTES);
            $newKey = $this->createKeyRecord(
                $tenantId,
                $plain,
                $oldKey->key_type === 'byok' ? 'byok' : 'system',
                'active',
                (int) $oldKey->tenant_key_id
            );

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->dispatchReEncrypt($tenantId, $oldKey, $newKey, $fieldsToReEncrypt);

        return $newKey;
    }

    /**
     * 使用新密钥重新加密已有数据
     *
     * @param  array<int, array{table: string, column: string, id_column?: string, tenant_column?: string}>  $fields
     * @return int 已处理记录数
     */
    public function reEncryptData(
        int $tenantId,
        array $fields,
        ?TenantKey $oldKey = null,
        ?TenantKey $newKey = null
    ): int {
        $oldKey = $oldKey ?? $this->getLatestRetiredKey($tenantId);
        $newKey = $newKey ?? $this->getActiveKey($tenantId);

        if ($oldKey === null || $newKey === null) {
            throw new \RuntimeException(trans('tenant.key_not_found'));
        }

        $oldPlain = $this->decryptKey($oldKey);
        $newPlain = $this->decryptKey($newKey);
        $count = 0;

        foreach ($fields as $field) {
            $table = $field['table'];
            $column = $field['column'];
            $idColumn = $field['id_column'] ?? 'id';
            $tenantColumn = $field['tenant_column'] ?? null;

            $query = DB::table($table)->whereNotNull($column);
            if ($tenantColumn !== null) {
                $query->where($tenantColumn, $tenantId);
            }

            $rows = $query->get();

            foreach ($rows as $row) {
                $value = $row->{$column} ?? null;
                if ($value === null) {
                    continue;
                }

                try {
                    $plain = $this->decryptWithKey($value, $oldPlain);
                } catch (\Exception $e) {
                    // 非旧密钥加密的数据，跳过
                    continue;
                }

                $reEncrypted = $this->encryptWithKey($plain, $newPlain);
                DB::table($table)
                    ->where($idColumn, $row->{$idColumn})
                    ->update([$column => $reEncrypted]);

                $count++;
            }
        }

        Log::info(trans('tenant.key_reencrypt_completed', ['count' => $count]), [
            'tenant_id' => $tenantId,
        ]);

        return $count;
    }

    /**
     * 获取最近一次退役的密钥（轮换过渡期解密使用）
     */
    public function getLatestRetiredKey(int $tenantId): ?TenantKey
    {
        return TenantKey::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('status', 'retired')
            ->orderBy('rotated_at', 'desc')
            ->first();
    }

    /**
     * 按主键查找密钥记录（绕过 TenantScope）
     */
    public function findKey(int $keyId): ?TenantKey
    {
        return TenantKey::withoutGlobalScope(TenantScope::class)->find($keyId);
    }

    /**
     * 创建密钥记录（绕过 TenantScope，显式指定 tenant_id）
     */
    private function createKeyRecord(
        int $tenantId,
        string $plainKey,
        string $keyType,
        string $status,
        ?int $previousKeyId = null
    ): TenantKey {
        $encrypted = $this->encryptWithMasterKey($plainKey);

        return TenantKey::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenantId,
            'encrypted_key' => $encrypted,
            'key_type' => $keyType,
            'status' => $status,
            'previous_key_id' => $previousKeyId,
        ]);
    }

    /**
     * 规范化 BYOK 密钥为 32 字节原始二进制
     *
     * @throws \RuntimeException 格式无效时抛出
     */
    private function normalizeByokKey(string $key): string
    {
        if (strlen($key) === self::KEY_LENGTH_BYTES) {
            return $key;
        }

        $decoded = base64_decode($key, true);
        if ($decoded !== false && strlen($decoded) === self::KEY_LENGTH_BYTES) {
            return $decoded;
        }

        if (ctype_xdigit($key) && strlen($key) === 64) {
            $hex = @hex2bin($key);
            if ($hex !== false && strlen($hex) === self::KEY_LENGTH_BYTES) {
                return $hex;
            }
        }

        throw new \RuntimeException(trans('tenant.key_byok_invalid'));
    }

    /**
     * 使用系统主密钥加密租户密钥（信封加密）
     */
    private function encryptWithMasterKey(string $plaintext): string
    {
        $masterKey = $this->getMasterKey();
        $iv = random_bytes(self::IV_LENGTH_BYTES);
        $encrypted = openssl_encrypt($plaintext, $this->cipher(), $masterKey, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new \RuntimeException(trans('tenant.key_decrypt_failed'));
        }

        return base64_encode($iv . $encrypted);
    }

    /**
     * 使用系统主密钥解密租户密钥
     */
    private function decryptWithMasterKey(string $payload): string
    {
        $masterKey = $this->getMasterKey();
        $raw = base64_decode($payload, true);

        if ($raw === false) {
            throw new \RuntimeException(trans('tenant.key_decrypt_failed'));
        }

        $iv = substr($raw, 0, self::IV_LENGTH_BYTES);
        $ciphertext = substr($raw, self::IV_LENGTH_BYTES);
        $decrypted = openssl_decrypt($ciphertext, $this->cipher(), $masterKey, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            throw new \RuntimeException(trans('tenant.key_decrypt_failed'));
        }

        return $decrypted;
    }

    /**
     * 使用租户密钥加密应用数据
     */
    private function encryptWithKey(string $plaintext, string $key): string
    {
        $iv = random_bytes(self::IV_LENGTH_BYTES);
        $encrypted = openssl_encrypt($plaintext, $this->cipher(), $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new \RuntimeException(trans('tenant.key_decrypt_failed'));
        }

        return base64_encode($iv . $encrypted);
    }

    /**
     * 使用租户密钥解密应用数据
     *
     * @throws \RuntimeException 解密失败时抛出
     */
    private function decryptWithKey(string $payload, string $key): string
    {
        $raw = base64_decode($payload, true);

        if ($raw === false) {
            throw new \RuntimeException(trans('tenant.key_decrypt_failed'));
        }

        $iv = substr($raw, 0, self::IV_LENGTH_BYTES);
        $ciphertext = substr($raw, self::IV_LENGTH_BYTES);
        $decrypted = openssl_decrypt($ciphertext, $this->cipher(), $key, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            throw new \RuntimeException(trans('tenant.key_decrypt_failed'));
        }

        return $decrypted;
    }

    /**
     * 获取系统主密钥（派生为 32 字节）
     *
     * @throws \RuntimeException 未配置时抛出
     */
    private function getMasterKey(): string
    {
        $key = config('tenancy.encryption.master_key');
        if (empty($key)) {
            throw new \RuntimeException(trans('tenant.key_master_key_missing'));
        }

        return hash('sha256', (string) $key, true);
    }

    /**
     * 获取加密算法
     */
    private function cipher(): string
    {
        return (string) config('tenancy.encryption.cipher', 'aes-256-cbc');
    }

    /**
     * 分发 re-encrypt 任务
     *
     * 若配置了 rotation_queue 则异步执行，否则同步执行。
     * 异步任务仅捕获标量数据，在 worker 中重新解析服务与密钥记录。
     */
    protected function dispatchReEncrypt(
        int $tenantId,
        TenantKey $oldKey,
        TenantKey $newKey,
        array $fields
    ): void {
        if (empty($fields)) {
            return;
        }

        $queue = config('tenancy.encryption.rotation_queue');

        if (! empty($queue)) {
            $oldKeyId = (int) $oldKey->tenant_key_id;
            $newKeyId = (int) $newKey->tenant_key_id;

            Queue::push(function () use ($tenantId, $fields, $oldKeyId, $newKeyId): void {
                $service = app(self::class);
                $old = $service->findKey($oldKeyId);
                $new = $service->findKey($newKeyId);

                if ($old !== null && $new !== null) {
                    $service->reEncryptData($tenantId, $fields, $old, $new);
                }
            }, null, (string) $queue);

            return;
        }

        $this->reEncryptData($tenantId, $fields, $oldKey, $newKey);
    }
}
