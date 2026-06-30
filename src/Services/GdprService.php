<?php

namespace MultiTenantSaas\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use MultiTenantSaas\Context\TenantContext;

/**
 * GDPR 合规服务
 *
 * 功能：
 *  - 用户数据导出（JSON，包含所有关联数据，支持数据可移植性）
 *  - 数据擦除（软删除 + 关键字段匿名化，符合 GDPR 要求）
 *  - 处理活动记录（GDPR Art. 30 要求）
 *  - 数据可移植性
 */
class GdprService
{
    /**
     * 数据导出时需排除的敏感字段（按表名）
     */
    protected array $sensitiveFields = [
        'users' => ['password', 'remember_token'],
        'mfa_devices' => ['secret'],
        'password_histories' => ['password_hash'],
        'oauth_accounts' => ['access_token', 'refresh_token'],
        'user_api_tokens' => ['apisvr_key'],
        'sso_providers' => ['client_secret', 'certificate'],
    ];

    /**
     * 数据擦除时需匿名化的用户字段
     */
    protected array $anonymizableUserFields = [
        'name' => '[erased]',
        'phone' => null,
        'avatar' => null,
    ];

    /**
     * 导出用户数据为结构化数组
     *
     * 包含用户的所有关联数据（tenants、sessions、api_tokens 等）。
     * 敏感字段（password/token/secret）永不导出。
     *
     * @param  int  $userId  用户 ID
     * @return array<string, mixed>
     */
    public function exportUserData(int $userId): array
    {
        $this->recordProcessingActivity($userId, 'data_export', [
            'exported_at' => now()->toIso8601String(),
        ]);

        $exportTypes = config('tenancy.gdpr.export_types', []);
        $result = ['exported_at' => now()->toIso8601String()];

        foreach ($exportTypes as $type) {
            $method = 'export'.str_replace('_', '', ucwords($type, '_'));
            if (method_exists($this, $method)) {
                $result[$type] = $this->$method($userId);
            }
        }

        return $result;
    }

    /**
     * 导出用户数据为 JSON 字符串（数据可移植性）
     *
     * @param  int  $userId  用户 ID
     * @return string JSON 格式的用户数据
     */
    public function exportToJson(int $userId): string
    {
        return json_encode(
            $this->exportUserData($userId),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * 擦除用户数据（软删除 + 匿名化）
     *
     * GDPR 要求：不物理删除，使用软删除 + 字段匿名化。
     * - 用户关键字段匿名化（name/email/phone/avatar/password）
     * - 撤销所有 API 令牌
     * - 删除会话记录
     * - 撤销所有同意
     * - 移除信任设备
     * - 软删除用户记录
     *
     * @param  int  $userId  用户 ID
     * @return bool 是否擦除成功
     */
    public function eraseUser(int $userId): bool
    {
        try {
            DB::transaction(function () use ($userId): void {
                $erasedEmail = 'erased_'.$userId.'@'.config('tenancy.gdpr.erasure_email_domain', 'deleted.local');

                // 1. 匿名化用户关键字段
                DB::table('users')->where('user_id', $userId)->update(array_merge(
                    $this->anonymizableUserFields,
                    [
                        'email' => $erasedEmail,
                        'password' => Hash::make(Str::random(64)),
                        'remember_token' => null,
                        'is_active' => false,
                        'deleted_at' => now(),
                    ]
                ));

                // 2. 撤销 API 令牌
                DB::table('personal_access_tokens')
                    ->where('tokenable_id', $userId)
                    ->where('tokenable_type', \MultiTenantSaas\Models\User::class)
                    ->delete();
                DB::table('user_api_tokens')->where('user_id', $userId)->delete();

                // 3. 删除会话记录
                DB::table('user_sessions')->where('user_id', $userId)->delete();

                // 4. 撤销所有同意
                DB::table('consents')
                    ->where('user_id', $userId)
                    ->whereNull('revoked_at')
                    ->update([
                        'is_granted' => false,
                        'revoked_at' => now(),
                    ]);

                // 5. 移除信任设备
                DB::table('trusted_devices')->where('user_id', $userId)->delete();

                // 6. 清理 MFA 设备
                DB::table('mfa_devices')->where('user_id', $userId)->delete();
            });

            $this->recordProcessingActivity($userId, 'data_erasure', [
                'reason' => 'user_request',
                'erased_at' => now()->toIso8601String(),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('GdprService eraseUser failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 记录处理活动（GDPR Art. 30 要求）
     *
     * @param  int  $userId  用户 ID
     * @param  string  $action  处理动作（data_export/data_erasure 等）
     * @param  array<string, mixed>  $context  上下文信息
     */
    public function recordProcessingActivity(int $userId, string $action, array $context = []): void
    {
        try {
            DB::table('structured_logs')->insert([
                'tenant_id' => TenantContext::getId(),
                'user_id' => $userId,
                'category' => 'gdpr',
                'action' => $action,
                'context' => json_encode($context),
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('GdprService recordProcessingActivity failed', [
                'user_id' => $userId,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 导出用户基本信息（排除敏感字段）
     */
    protected function exportUser(int $userId): array
    {
        $user = DB::table('users')->where('user_id', $userId)->first();

        if (! $user) {
            return [];
        }

        return $this->filterSensitive((array) $user, 'users');
    }

    /**
     * 导出用户关联的租户列表
     */
    protected function exportTenants(int $userId): array
    {
        $tenants = DB::table('tenant_users')
            ->leftJoin('tenants', 'tenant_users.tenant_id', '=', 'tenants.tenant_id')
            ->where('tenant_users.user_id', $userId)
            ->get();

        return $tenants->map(fn ($t) => (array) $t)->toArray();
    }

    /**
     * 导出用户会话记录
     */
    protected function exportSessions(int $userId): array
    {
        $sessions = DB::table('user_sessions')->where('user_id', $userId)->get();

        return $sessions->map(fn ($s) => (array) $s)->toArray();
    }

    /**
     * 导出 API 令牌
     */
    protected function exportApiTokens(int $userId): array
    {
        $tokens = DB::table('personal_access_tokens')
            ->where('tokenable_id', $userId)
            ->where('tokenable_type', \MultiTenantSaas\Models\User::class)
            ->get();

        $userTokens = DB::table('user_api_tokens')->where('user_id', $userId)->get();

        return [
            'personal_access_tokens' => $tokens->map(fn ($t) => $this->filterSensitive((array) $t, 'user_api_tokens'))->toArray(),
            'user_api_tokens' => $userTokens->map(fn ($t) => $this->filterSensitive((array) $t, 'user_api_tokens'))->toArray(),
        ];
    }

    /**
     * 导出 OAuth 账户（排除 access_token / refresh_token）
     */
    protected function exportOauthAccounts(int $userId): array
    {
        $accounts = DB::table('oauth_accounts')->where('user_id', $userId)->get();

        return $accounts->map(fn ($a) => $this->filterSensitive((array) $a, 'oauth_accounts'))->toArray();
    }

    /**
     * 导出 MFA 设备（排除 secret）
     */
    protected function exportMfaDevices(int $userId): array
    {
        $devices = DB::table('mfa_devices')->where('user_id', $userId)->get();

        return $devices->map(fn ($d) => $this->filterSensitive((array) $d, 'mfa_devices'))->toArray();
    }

    /**
     * 导出信任设备
     */
    protected function exportTrustedDevices(int $userId): array
    {
        $devices = DB::table('trusted_devices')->where('user_id', $userId)->get();

        return $devices->map(fn ($d) => (array) $d)->toArray();
    }

    /**
     * 导出密码历史（排除 password_hash）
     */
    protected function exportPasswordHistories(int $userId): array
    {
        $histories = DB::table('password_histories')->where('user_id', $userId)->get();

        return $histories->map(fn ($h) => $this->filterSensitive((array) $h, 'password_histories'))->toArray();
    }

    /**
     * 导出同意记录
     */
    protected function exportConsents(int $userId): array
    {
        $consents = DB::table('consents')->where('user_id', $userId)->get();

        return $consents->map(fn ($c) => (array) $c)->toArray();
    }

    /**
     * 导出审计日志
     */
    protected function exportAuditLogs(int $userId): array
    {
        $logs = DB::table('audit_logs')->where('user_id', $userId)->get();

        return $logs->map(fn ($l) => (array) $l)->toArray();
    }

    /**
     * 导出 AI 请求记录
     */
    protected function exportAiRequests(int $userId): array
    {
        $requests = DB::table('ai_requests')->where('user_id', $userId)->get();

        return $requests->map(fn ($r) => (array) $r)->toArray();
    }

    /**
     * 导出积分交易记录
     */
    protected function exportCreditTransactions(int $userId): array
    {
        $transactions = DB::table('credit_transactions')->where('user_id', $userId)->get();

        return $transactions->map(fn ($t) => (array) $t)->toArray();
    }

    /**
     * 导出文件上传记录
     */
    protected function exportFileUploads(int $userId): array
    {
        $files = DB::table('file_uploads')->where('user_id', $userId)->get();

        return $files->map(fn ($f) => (array) $f)->toArray();
    }

    /**
     * 过滤敏感字段
     *
     * @param  array<string, mixed>  $data  原始数据
     * @param  string  $table  表名
     * @return array<string, mixed> 过滤后的数据
     */
    protected function filterSensitive(array $data, string $table): array
    {
        $fields = $this->sensitiveFields[$table] ?? [];

        foreach ($fields as $field) {
            unset($data[$field]);
        }

        return $data;
    }
}
