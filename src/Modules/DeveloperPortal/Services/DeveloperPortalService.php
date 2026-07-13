<?php

namespace MultiTenantSaas\Modules\DeveloperPortal\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Logging\Models\AuditLog;

/**
 * 开发者门户服务
 *
 * 功能：
 *  - API Key 管理（创建 / 吊销 / 权限范围）
 *  - API 使用统计（基于 structured_logs 聚合）
 *  - 文档集成（返回内置文档目录与引用）
 *
 * API Key 基于 Laravel Sanctum 的 PersonalAccessToken 实现，
 * abilities 字段即权限范围（scopes）。
 */
class DeveloperPortalService
{
    /**
     * 可用的 API Key 权限范围
     */
    public const AVAILABLE_SCOPES = [
        'tenant:read',
        'tenant:write',
        'payment:read',
        'payment:write',
        'ai:text',
        'ai:image',
        'ai:video',
        'ai:usage',
        '*',
    ];

    /**
     * 内置文档目录
     */
    public const DOCUMENTATION = [
        [
            'id' => 'getting-started',
            'title' => '快速开始',
            'category' => '基础',
            'description' => 'SDK 安装、鉴权与首个请求。',
        ],
        [
            'id' => 'authentication',
            'title' => 'API Key 鉴权',
            'category' => '基础',
            'description' => 'API Key 创建、权限范围与 Bearer Token 鉴权流程。',
        ],
        [
            'id' => 'tenants',
            'title' => '租户管理 API',
            'category' => '资源',
            'description' => '租户的创建、查询、更新、挂起与激活。',
        ],
        [
            'id' => 'payments',
            'title' => '支付订单 API',
            'category' => '资源',
            'description' => '支付订单创建、查询与退款。',
        ],
        [
            'id' => 'ai',
            'title' => 'AI 服务 API',
            'category' => '资源',
            'description' => '文本补全、图像生成与视频生成。',
        ],
        [
            'id' => 'webhooks',
            'title' => 'Webhook 事件订阅',
            'category' => '集成',
            'description' => '事件类型、签名验证与交付重试机制。',
        ],
        [
            'id' => 'rate-limits',
            'title' => '速率限制',
            'category' => '集成',
            'description' => '接口速率限制策略与 429 处理。',
        ],
        [
            'id' => 'errors',
            'title' => '错误码参考',
            'category' => '集成',
            'description' => '统一错误响应结构与标准错误码表。',
        ],
        [
            'id' => 'sandbox',
            'title' => '沙箱环境',
            'category' => '开发',
            'description' => '独立沙箱环境、测试 API Key 与数据自动清理。',
        ],
    ];

    /** API Key 名称最大长度 */
    public const NAME_MAX_LENGTH = 100;

    // ----------------------------------------
    // API Key 管理
    // ----------------------------------------

    /**
     * 创建 API Key
     *
     * 返回明文 Token（仅此一次）与元数据。
     *
     * @param  int  $userId  开发者用户 ID
     * @param  string  $name  API Key 名称
     * @param  array<int, string>  $abilities  权限范围
     * @return array{token:string, id:int, name:string, abilities:array<int,string>}
     *
     * @throws \InvalidArgumentException
     */
    public function createApiKey(int $userId, string $name, array $abilities = ['*']): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException(trans('common.api_key_name_required'));
        }
        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new \InvalidArgumentException(trans('common.api_key_name_too_long'));
        }

        $this->validateScopes($abilities);

        $user = User::where('user_id', $userId)->first();
        if (! $user) {
            throw new \InvalidArgumentException(trans('common.not_found'));
        }

        $token = $user->createToken($name, $abilities);

        $this->audit('developer_portal.api_key.create', $token->accessToken->id, null, [
            'name' => $name,
            'abilities' => $abilities,
        ]);

        return [
            'token' => $token->plainTextToken,
            'id' => (int) $token->accessToken->id,
            'name' => $name,
            'abilities' => $abilities,
        ];
    }

    /**
     * 吊销 API Key
     */
    public function revokeApiKey(int $userId, int $tokenId): bool
    {
        $deleted = DB::table('personal_access_tokens')
            ->where('id', $tokenId)
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $userId)
            ->delete();

        if ($deleted > 0) {
            $this->audit('developer_portal.api_key.revoke', $tokenId, null, null);

            return true;
        }

        return false;
    }

    /**
     * 更新 API Key 权限范围
     *
     * @param  array<int, string>  $abilities
     */
    public function updateApiKeyScopes(int $userId, int $tokenId, array $abilities): bool
    {
        $this->validateScopes($abilities);

        $updated = DB::table('personal_access_tokens')
            ->where('id', $tokenId)
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $userId)
            ->update(['abilities' => json_encode($abilities, JSON_UNESCAPED_UNICODE)]);

        if ($updated > 0) {
            $this->audit('developer_portal.api_key.update_scopes', $tokenId, null, ['abilities' => $abilities]);

            return true;
        }

        return false;
    }

    /**
     * 列出开发者的 API Key
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listApiKeys(int $userId): Collection
    {
        return DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $userId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'abilities' => $this->decodeAbilities($row->abilities),
                'last_used_at' => $row->last_used_at,
                'expires_at' => $row->expires_at,
                'created_at' => $row->created_at,
            ]);
    }

    /**
     * 查找单个 API Key
     *
     * @return array<string, mixed>|null
     */
    public function findApiKey(int $userId, int $tokenId): ?array
    {
        $row = DB::table('personal_access_tokens')
            ->where('id', $tokenId)
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $userId)
            ->first();

        if (! $row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'name' => $row->name,
            'abilities' => $this->decodeAbilities($row->abilities),
            'last_used_at' => $row->last_used_at,
            'expires_at' => $row->expires_at,
            'created_at' => $row->created_at,
        ];
    }

    // ----------------------------------------
    // API 使用统计
    // ----------------------------------------

    /**
     * 获取开发者的 API 使用统计
     *
     * @param  int  $userId  开发者用户 ID
     * @param  int|null  $tokenId  限定 API Key（可选）
     * @return array{total:int, by_endpoint:array<string,int>, recent:array<int, array<string,mixed>>}
     */
    public function getUsageStats(int $userId, ?int $tokenId = null): array
    {
        $query = DB::table('structured_logs')
            ->where('user_id', $userId)
            ->where('category', 'api');

        if ($tokenId !== null) {
            $query->where('context->api_key_id', $tokenId);
        }

        $total = $query->count();

        $byEndpoint = (clone $query)
            ->select('action', DB::raw('COUNT(*) as cnt'))
            ->groupBy('action')
            ->orderByDesc('cnt')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->action => (int) $row->cnt])
            ->all();

        $recent = (clone $query)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'action' => $row->action,
                'context' => $this->decodeContext($row->context),
                'ip_address' => $row->ip_address,
                'created_at' => $row->created_at,
            ])
            ->all();

        return [
            'total' => (int) $total,
            'by_endpoint' => $byEndpoint,
            'recent' => $recent,
        ];
    }

    // ----------------------------------------
    // 文档集成
    // ----------------------------------------

    /**
     * 获取文档目录
     *
     * @return array<int, array<string, string>>
     */
    public function getDocumentation(): array
    {
        return self::DOCUMENTATION;
    }

    /**
     * 按分类获取文档
     *
     * @return array<int, array<string, string>>
     */
    public function getDocumentationByCategory(string $category): array
    {
        return array_values(array_filter(
            self::DOCUMENTATION,
            fn ($doc) => $doc['category'] === $category,
        ));
    }

    /**
     * 获取文档分类列表
     *
     * @return array<int, string>
     */
    public function getDocumentationCategories(): array
    {
        return array_values(array_unique(array_map(fn ($doc) => $doc['category'], self::DOCUMENTATION)));
    }

    // ----------------------------------------
    // 内部辅助
    // ----------------------------------------

    /**
     * 校验权限范围
     *
     * @param  array<int, string>  $abilities
     *
     * @throws \InvalidArgumentException
     */
    private function validateScopes(array $abilities): void
    {
        if (empty($abilities)) {
            throw new \InvalidArgumentException(trans('common.api_key_scopes_required'));
        }

        foreach ($abilities as $ability) {
            if (! is_string($ability)) {
                throw new \InvalidArgumentException(trans('common.api_key_scope_invalid'));
            }
            if (! in_array($ability, self::AVAILABLE_SCOPES, true)) {
                throw new \InvalidArgumentException(trans('common.api_key_scope_invalid') . ': ' . $ability);
            }
        }
    }

    /**
     * 解析 abilities 字段
     *
     * @return array<int, string>
     */
    private function decodeAbilities(?string $raw): array
    {
        if (! $raw) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /**
     * 解析 context 字段
     *
     * @return array<string, mixed>
     */
    private function decodeContext(?string $raw): array
    {
        if (! $raw) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 记录审计日志
     *
     * @param  array|string|null  $oldValues
     * @param  array|string|null  $newValues
     */
    protected function audit(string $action, ?int $resourceId, $oldValues = null, $newValues = null): void
    {
        try {
            AuditLog::create([
                'tenant_id' => TenantContext::getId(),
                'user_id' => auth()->id(),
                'action' => $action,
                'resource_type' => 'api_key',
                'resource_id' => $resourceId,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('DeveloperPortalService audit failed', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
