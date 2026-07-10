<?php

namespace MultiTenantSaas\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * API 版本管理服务
 *
 * 提供 API 版本控制、废弃通知与兼容性检查。
 *
 * 版本规则：v1, v2, ... (URL 前缀 /api/v{N}/) 或 Header `X-API-Version: 1`。
 */
class ApiVersionService
{
    public const STATUS_STABLE = 'stable';

    public const STATUS_DEPRECATED = 'deprecated';

    public const STATUS_SUNSET = 'sunset';

    public const STATUS_DRAFT = 'draft';

    protected const TABLE = 'api_versions';

    /**
     * 注册 API 版本
     *
     * @param  array{
     *   version: string,
     *   status: string,
     *   release_date: string|null,
     *   sunset_date: string|null,
     *   notes: string|null
     * }  $version  版本定义
     * @return int 版本 ID
     *
     * @throws \RuntimeException 版本已存在
     */
    public function registerVersion(array $version): int
    {
        $versionStr = $version['version'] ?? '';
        if (empty($versionStr)) {
            throw new \RuntimeException(trans('common.version_required'));
        }

        $exists = DB::table(self::TABLE)->where('version', $versionStr)->exists();
        if ($exists) {
            throw new \RuntimeException(trans('common.version_already_exists', ['version' => $versionStr]));
        }

        return (int) DB::table(self::TABLE)->insertGetId([
            'version' => $versionStr,
            'status' => $version['status'] ?? self::STATUS_STABLE,
            'release_date' => $version['release_date'] ?? null,
            'sunset_date' => $version['sunset_date'] ?? null,
            'notes' => $version['notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 标记版本为废弃
     *
     * @param  string  $version  版本号
     * @param  string|null  $sunsetDate  下线日期
     * @return int 受影响行数
     */
    public function deprecateVersion(string $version, ?string $sunsetDate = null): int
    {
        $affected = DB::table(self::TABLE)
            ->where('version', $version)
            ->update([
                'status' => self::STATUS_DEPRECATED,
                'sunset_date' => $sunsetDate,
                'updated_at' => now(),
            ]);

        AuditService::log(
            action: 'api_version_deprecated',
            resourceType: 'api_version',
            newValues: ['version' => $version, 'sunset_date' => $sunsetDate]
        );

        return $affected;
    }

    /**
     * 列出所有版本
     *
     * @param  int  $perPage  每页条数
     */
    public function listVersions(int $perPage = 15): LengthAwarePaginator
    {
        return DB::table(self::TABLE)
            ->orderByRaw('CAST(SUBSTRING(version, 2) AS UNSIGNED) DESC')
            ->paginate($perPage);
    }

    /**
     * 获取当前生效的版本（status=stable 或 deprecated 但未到 sunset_date）
     */
    public function getActiveVersions(): Collection
    {
        return DB::table(self::TABLE)
            ->whereIn('status', [self::STATUS_STABLE, self::STATUS_DEPRECATED])
            ->where(function ($q) {
                $q->whereNull('sunset_date')->orWhere('sunset_date', '>', now());
            })
            ->orderByRaw('CAST(SUBSTRING(version, 2) AS UNSIGNED) DESC')
            ->get();
    }

    /**
     * 从请求解析 API 版本
     *
     * 优先级：URL 前缀 > Header X-API-Version > 默认
     *
     * @param  Request  $request  当前请求
     * @return string 解析出的版本号（如 "v1"）
     */
    public function resolveVersionFromRequest(Request $request): string
    {
        $path = $request->path();
        if (preg_match('#^api/(v\d+)/#i', $path, $m)) {
            return strtolower($m[1]);
        }

        $headerVersion = $request->header('X-API-Version');
        if ($headerVersion) {
            $num = ltrim($headerVersion, 'vV');

            return 'v' . $num;
        }

        return config('tenancy.api_default_version', 'v1');
    }

    /**
     * 检查请求使用的版本是否已被废弃
     *
     * @param  Request  $request  当前请求
     * @return array{deprecated: bool, sunset_date: string|null, message: string|null}
     */
    public function checkDeprecation(Request $request): array
    {
        $version = $this->resolveVersionFromRequest($request);

        $record = DB::table(self::TABLE)->where('version', $version)->first();

        if (! $record || $record->status === self::STATUS_STABLE) {
            return ['deprecated' => false, 'sunset_date' => null, 'message' => null];
        }

        $sunset = $record->sunset_date;
        $message = $sunset
            ? trans('common.api_version_sunset', ['version' => $version, 'date' => $sunset])
            : trans('common.api_version_deprecated', ['version' => $version]);

        return [
            'deprecated' => true,
            'sunset_date' => $sunset,
            'message' => $message,
        ];
    }

    /**
     * 兼容性检查：v1 端点在 v2 是否仍然支持
     *
     * @param  string  $route  路由
     * @param  string  $version  版本
     * @return bool true 表示兼容
     */
    public function isCompatible(string $route, string $version): bool
    {
        $routeCollection = Route::getRoutes();
        $targetRoute = "api/{$version}/" . ltrim($route, '/');

        return $routeCollection->has($targetRoute);
    }

    /**
     * 给废弃响应添加 deprecation headers
     *
     * @param  Response  $response  响应实例
     * @param  Request  $request  请求实例
     */
    public function addDeprecationHeaders($response, Request $request): Response
    {
        $info = $this->checkDeprecation($request);

        if ($info['deprecated']) {
            $response->headers->set('Deprecation', 'true');
            if ($info['sunset_date']) {
                $response->headers->set('Sunset', $info['sunset_date']);
            }
            $response->headers->set('X-Deprecation-Notice', $info['message'] ?? '');
        }

        return $response;
    }
}
