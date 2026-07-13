<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue as QueueFacade;
use Illuminate\Support\Facades\Storage;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;

/**
 * 系统健康检查服务
 *
 * 提供基础设施健康检查（数据库/Redis/队列/存储）与业务可用性检查。
 * 与现有 HealthService（基于 spatie/laravel-health）互补：
 *  - HealthService 关注 Laravel 框架层（config 状态、缓存、debug 等）
 *  - 本服务关注业务层（租户服务、支付服务、OAuth 服务、第三方服务）
 */
class HealthCheckService
{
    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_UNHEALTHY = 'unhealthy';

    protected const CACHE_TTL = 30;

    /**
     * 执行全部检查并返回汇总状态
     *
     * @param  bool  $useCache  是否使用缓存（默认 true，避免高频检查压垮依赖）
     * @return array{
     *   status: string,
     *   checks: array<string, array{status: string, message: string, latency_ms: int}>
     * }
     */
    public function checkAll(bool $useCache = true): array
    {
        return Cache::remember('health:checkall', self::CACHE_TTL, function () {
            $checks = [];

            $checks['database'] = $this->checkDatabase();
            $checks['redis'] = $this->checkRedis();
            $checks['queue'] = $this->checkQueue();
            $checks['storage'] = $this->checkStorage();
            $checks['tenant_service'] = $this->checkTenantService();
            $checks['payment_service'] = $this->checkPaymentService();
            $checks['oauth_service'] = $this->checkOauthService();
            $checks['external_apis'] = $this->checkExternalApis();

            $hasUnhealthy = false;
            $hasDegraded = false;
            foreach ($checks as $c) {
                if ($c['status'] === self::STATUS_UNHEALTHY) {
                    $hasUnhealthy = true;
                } elseif ($c['status'] === self::STATUS_DEGRADED) {
                    $hasDegraded = true;
                }
            }

            $status = self::STATUS_HEALTHY;
            if ($hasUnhealthy) {
                $status = self::STATUS_UNHEALTHY;
            } elseif ($hasDegraded) {
                $status = self::STATUS_DEGRADED;
            }

            return [
                'status' => $status,
                'checks' => $checks,
            ];
        });
    }

    /**
     * 数据库连接检查
     *
     * @return array{status: string, message: string, latency_ms: int}
     */
    public function checkDatabase(): array
    {
        $start = microtime(true);
        try {
            DB::select('SELECT 1');
            $latency = (int) ((microtime(true) - $start) * 1000);

            return [
                'status' => $latency < 1000 ? self::STATUS_HEALTHY : self::STATUS_DEGRADED,
                'message' => "OK ({$latency}ms)",
                'latency_ms' => $latency,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => self::STATUS_UNHEALTHY,
                'message' => $e->getMessage(),
                'latency_ms' => 0,
            ];
        }
    }

    /**
     * Redis 连接检查
     *
     * @return array{status: string, message: string, latency_ms: int}
     */
    public function checkRedis(): array
    {
        if (config('cache.default') !== 'redis') {
            return [
                'status' => self::STATUS_HEALTHY,
                'message' => 'Redis not configured (skipped)',
                'latency_ms' => 0,
            ];
        }

        $start = microtime(true);
        try {
            Cache::store('redis')->put('health:ping', 1, 5);
            Cache::store('redis')->forget('health:ping');
            $latency = (int) ((microtime(true) - $start) * 1000);

            return [
                'status' => $latency < 500 ? self::STATUS_HEALTHY : self::STATUS_DEGRADED,
                'message' => "OK ({$latency}ms)",
                'latency_ms' => $latency,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => self::STATUS_UNHEALTHY,
                'message' => $e->getMessage(),
                'latency_ms' => 0,
            ];
        }
    }

    /**
     * 队列服务检查
     *
     * @return array{status: string, message: string, latency_ms: int}
     */
    public function checkQueue(): array
    {
        $start = microtime(true);
        try {
            // 简单检查队列连接可用性（不实际派发任务）
            $size = QueueFacade::size();
            $latency = (int) ((microtime(true) - $start) * 1000);

            $status = $size > 1000 ? self::STATUS_DEGRADED : self::STATUS_HEALTHY;

            return [
                'status' => $status,
                'message' => "Queue size: {$size} ({$latency}ms)",
                'latency_ms' => $latency,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => self::STATUS_UNHEALTHY,
                'message' => $e->getMessage(),
                'latency_ms' => 0,
            ];
        }
    }

    /**
     * 存储服务检查
     *
     * @return array{status: string, message: string, latency_ms: int}
     */
    public function checkStorage(): array
    {
        $disk = config('tenancy.file_storage_disk', 'local');
        $start = microtime(true);
        try {
            $path = 'health/' . uniqid('ping_', true);
            Storage::disk($disk)->put($path, 'ping');
            $exists = Storage::disk($disk)->exists($path);
            Storage::disk($disk)->delete($path);
            $latency = (int) ((microtime(true) - $start) * 1000);

            if (! $exists) {
                return [
                    'status' => self::STATUS_UNHEALTHY,
                    'message' => 'Storage write/read failed',
                    'latency_ms' => $latency,
                ];
            }

            return [
                'status' => self::STATUS_HEALTHY,
                'message' => "OK (disk: {$disk}, {$latency}ms)",
                'latency_ms' => $latency,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => self::STATUS_UNHEALTHY,
                'message' => $e->getMessage(),
                'latency_ms' => 0,
            ];
        }
    }

    /**
     * 租户服务可用性检查
     *
     * @return array{status: string, message: string, latency_ms: int}
     */
    public function checkTenantService(): array
    {
        $start = microtime(true);
        try {
            $count = Tenant::where('status', 'active')->count();
            $latency = (int) ((microtime(true) - $start) * 1000);

            return [
                'status' => self::STATUS_HEALTHY,
                'message' => "Active tenants: {$count} ({$latency}ms)",
                'latency_ms' => $latency,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => self::STATUS_UNHEALTHY,
                'message' => $e->getMessage(),
                'latency_ms' => 0,
            ];
        }
    }

    /**
     * 支付服务可用性检查（仅检查配置是否就绪，不发起真实交易）
     *
     * @return array{status: string, message: string, latency_ms: int}
     */
    public function checkPaymentService(): array
    {
        $start = microtime(true);
        try {
            $tenantId = (int) (TenantContext::getId() ?? config('tenancy.default_tenant_id') ?? 0);

            if ($tenantId > 0) {
                $wechatConfigured = PayService::isConfigured($tenantId, 'wechat');
                $alipayConfigured = PayService::isConfigured($tenantId, 'alipay');

                $latency = (int) ((microtime(true) - $start) * 1000);

                if (! $wechatConfigured && ! $alipayConfigured) {
                    return [
                        'status' => self::STATUS_DEGRADED,
                        'message' => 'No payment configured',
                        'latency_ms' => $latency,
                    ];
                }

                return [
                    'status' => self::STATUS_HEALTHY,
                    'message' => 'Payment configured',
                    'latency_ms' => $latency,
                ];
            }

            return [
                'status' => self::STATUS_HEALTHY,
                'message' => 'No tenant context (skipped)',
                'latency_ms' => 0,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => self::STATUS_UNHEALTHY,
                'message' => $e->getMessage(),
                'latency_ms' => 0,
            ];
        }
    }

    /**
     * OAuth 服务可用性检查
     *
     * @return array{status: string, message: string, latency_ms: int}
     */
    public function checkOauthService(): array
    {
        $start = microtime(true);
        try {
            $tenantId = (int) (TenantContext::getId() ?? 0);

            if ($tenantId > 0) {
                $providers = SocialiteService::getOAuthConfigForDisplay($tenantId);
                $configuredCount = collect($providers)->filter(fn ($p) => $p['configured'] ?? false)->count();
                $latency = (int) ((microtime(true) - $start) * 1000);

                return [
                    'status' => self::STATUS_HEALTHY,
                    'message' => "OAuth providers configured: {$configuredCount}",
                    'latency_ms' => $latency,
                ];
            }

            return [
                'status' => self::STATUS_HEALTHY,
                'message' => 'No tenant context (skipped)',
                'latency_ms' => 0,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => self::STATUS_UNHEALTHY,
                'message' => $e->getMessage(),
                'latency_ms' => 0,
            ];
        }
    }

    /**
     * 第三方服务可用性检查
     *
     * @return array{status: string, message: string, latency_ms: int}
     */
    public function checkExternalApis(): array
    {
        $endpoints = array_filter([
            config('alert.webhook_url'),
            config('services.dify.base_url'),
        ]);

        if (empty($endpoints)) {
            return [
                'status' => self::STATUS_HEALTHY,
                'message' => 'No external APIs configured',
                'latency_ms' => 0,
            ];
        }

        $start = microtime(true);
        $failed = 0;

        foreach ($endpoints as $url) {
            try {
                $response = Http::timeout(3)->get($url);
                if (! $response->successful()) {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        $latency = (int) ((microtime(true) - $start) * 1000);

        $status = $failed === 0
            ? self::STATUS_HEALTHY
            : ($failed < count($endpoints) ? self::STATUS_DEGRADED : self::STATUS_UNHEALTHY);

        return [
            'status' => $status,
            'message' => (count($endpoints) - $failed) . '/' . count($endpoints) . ' endpoints healthy',
            'latency_ms' => $latency,
        ];
    }
}
