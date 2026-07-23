<?php

namespace MultiTenantSaas\Modules\User\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Logging\Models\AuditLog;
use MultiTenantSaas\Modules\Logging\Services\AuditService;

/**
 * 用户画像服务
 *
 * 负责用户基本信息、偏好设置、登录日志与设备管理。
 *
 * 租户隔离：所有查询方法均按 TenantContext::getId() 过滤；
 * 写入方法接受显式 $tenantId 参数以避免跨租户操作。
 */
class UserProfileService
{
    /**
     * 用户偏好默认值
     */
    protected const DEFAULT_PREFERENCES = [
        'language' => 'zh-CN',
        'timezone' => 'Asia/Shanghai',
        'theme' => 'light',
        'notifications' => [
            'email' => true,
            'sms' => false,
            'web' => true,
        ],
    ];

    /**
     * 偏好表名
     */
    protected const TABLE_PREFERENCES = 'user_preferences';

    /**
     * 获取用户基本信息
     *
     * @param  int  $userId  目标用户 ID
     *
     * @throws ModelNotFoundException 用户不存在
     */
    public function getProfile(int $userId): User
    {
        return User::with(['tenants', 'creditAccounts', 'oauthAccounts'])
            ->findOrFail($userId);
    }

    /**
     * 更新用户基本信息
     *
     * @param  int  $userId  目标用户 ID
     * @param  array{name?: string, avatar?: string, phone?: string}  $data
     *
     * @throws ModelNotFoundException
     * @throws \RuntimeException 数据库写入失败
     */
    public function updateProfile(int $userId, array $data): User
    {
        DB::beginTransaction();
        try {
            $user = User::findOrFail($userId);

            $user->update([
                'name' => $data['name'] ?? $user->name,
                'avatar' => $data['avatar'] ?? $user->avatar,
                'phone' => $data['phone'] ?? $user->phone,
            ]);

            app(AuditService::class)->log(
                action: 'profile_update',
                resourceType: 'user',
                resourceId: $userId,
                newValues: $data
            );

            DB::commit();

            return $user->fresh();
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \RuntimeException(trans('common.profile_update_failed') . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 获取用户偏好设置
     *
     * @param  int  $userId  目标用户 ID
     * @return array 用户偏好数组（始终包含全量字段，缺失项回退默认值）
     */
    public function getPreferences(int $userId): array
    {
        $row = DB::table(self::TABLE_PREFERENCES)
            ->where('user_id', $userId)
            ->first();

        $stored = $row && $row->preferences
            ? json_decode($row->preferences, true)
            : [];

        if (! is_array($stored)) {
            $stored = [];
        }

        return array_replace_recursive(self::DEFAULT_PREFERENCES, $stored);
    }

    /**
     * 更新用户偏好设置（部分更新）
     *
     * @param  int  $userId  目标用户 ID
     * @param  array  $preferences  待合并的偏好项
     * @return array 更新后的完整偏好数组
     *
     * @throws ModelNotFoundException
     * @throws \RuntimeException 写入失败
     */
    public function updatePreferences(int $userId, array $preferences): array
    {
        User::findOrFail($userId);

        DB::beginTransaction();
        try {
            $merged = array_replace_recursive($this->getPreferences($userId), $preferences);

            $exists = DB::table(self::TABLE_PREFERENCES)
                ->where('user_id', $userId)
                ->exists();

            if ($exists) {
                DB::table(self::TABLE_PREFERENCES)
                    ->where('user_id', $userId)
                    ->update([
                        'preferences' => json_encode($merged, JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table(self::TABLE_PREFERENCES)->insert([
                    'user_id' => $userId,
                    'preferences' => json_encode($merged, JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            app(AuditService::class)->log(
                action: 'preferences_update',
                resourceType: 'user',
                resourceId: $userId,
                newValues: $preferences
            );

            DB::commit();

            return $merged;
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \RuntimeException(trans('common.preferences_update_failed') . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 重置用户偏好设置为默认值
     *
     * @param  int  $userId  目标用户 ID
     * @return array 重置后的偏好数组
     */
    public function resetPreferences(int $userId): array
    {
        DB::beginTransaction();
        try {
            DB::table(self::TABLE_PREFERENCES)
                ->where('user_id', $userId)
                ->delete();

            app(AuditService::class)->log(
                action: 'preferences_reset',
                resourceType: 'user',
                resourceId: $userId
            );

            DB::commit();

            return self::DEFAULT_PREFERENCES;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \RuntimeException(trans('common.preferences_reset_failed') . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 记录用户登录日志
     *
     * 直接写入 AuditLog 以确保 user_id 字段被正确填充
     * （AuditService::log 依赖 auth()->id()，在队列/CLI 场景下不可用）。
     *
     * @param  int  $userId  登录用户 ID
     * @param  \Illuminate\Http\Request|null  $request  请求实例（可选，默认取当前请求）
     */
    public function recordLogin(int $userId, ?\Illuminate\Http\Request $request = null): AuditLog
    {
        $request = $request ?: Request::instance();

        return AuditLog::create([
            'tenant_id' => TenantContext::getId(),
            'user_id' => $userId,
            'action' => 'login',
            'resource_type' => 'user',
            'resource_id' => $userId,
            'new_values' => [
                'ip' => $request->ip(),
                'user_agent' => substr($request->userAgent() ?? '', 0, 500),
                'device' => $this->detectDevice($request->userAgent()),
                'at' => now()->toIso8601String(),
            ],
            'ip_address' => $request->ip(),
            'user_agent' => substr($request->userAgent() ?? '', 0, 500),
        ]);
    }

    /**
     * 获取用户登录日志
     *
     * @param  int  $userId  目标用户 ID
     * @param  int  $limit  返回条数（默认 20）
     * @return Collection<int, AuditLog>
     */
    public function getLoginLogs(int $userId, int $limit = 20): Collection
    {
        return AuditLog::where('user_id', $userId)
            ->where('action', 'login')
            ->where('resource_type', 'user')
            ->where('resource_id', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * 获取用户设备列表
     *
     * @param  int  $userId  目标用户 ID
     * @return array<int, array{device: string, ip: string, user_agent: string, last_seen: string}>
     */
    public function getDevices(int $userId): array
    {
        $logs = $this->getLoginLogs($userId, 100);

        $devices = [];
        foreach ($logs as $log) {
            $meta = is_array($log->new_values) ? $log->new_values : [];
            $devices[] = [
                'device' => $meta['device'] ?? 'unknown',
                'ip' => $meta['ip'] ?? $log->ip_address,
                'user_agent' => $meta['user_agent'] ?? $log->user_agent,
                'last_seen' => $log->created_at?->toIso8601String(),
            ];
        }

        return array_slice($devices, 0, 20);
    }

    /**
     * 注销指定设备（按 IP + User-Agent 反查并删除最近的登录日志）
     *
     * @param  int  $userId  目标用户 ID
     * @param  string  $ip  设备 IP
     * @return int 删除的日志条数
     */
    public function revokeDevice(int $userId, string $ip): int
    {
        return AuditLog::where('user_id', $userId)
            ->where('action', 'login')
            ->where('resource_type', 'user')
            ->where('resource_id', $userId)
            ->where('ip_address', $ip)
            ->delete();
    }

    /**
     * 检测异常登录
     *
     * 简化策略：同一用户在 10 分钟内出现不同 IP 视为异常。
     *
     * @param  int  $userId  目标用户 ID
     * @param  string  $currentIp  当前登录 IP
     * @return bool 是否检测到异常
     */
    public function detectAnomalousLogin(int $userId, string $currentIp): bool
    {
        $recent = AuditLog::where('user_id', $userId)
            ->where('action', 'login')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return $recent->contains(fn ($log) => $log->ip_address && $log->ip_address !== $currentIp);
    }

    /**
     * 分页查询租户内用户登录日志
     *
     * @param  int  $tenantId  租户 ID
     * @param  int  $perPage  每页条数
     */
    public function listTenantLoginLogs(int $tenantId, int $perPage = 15): LengthAwarePaginator
    {
        return AuditLog::where('tenant_id', $tenantId)
            ->where('action', 'login')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * 通过 User-Agent 检测设备类型
     */
    protected function detectDevice(?string $userAgent): string
    {
        if (empty($userAgent)) {
            return 'unknown';
        }

        return match (true) {
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'ios',
            str_contains($userAgent, 'Android') => 'android',
            str_contains($userAgent, 'Mac OS') => 'macos',
            str_contains($userAgent, 'Windows') => 'windows',
            str_contains($userAgent, 'Linux') => 'linux',
            default => 'unknown',
        };
    }
}
