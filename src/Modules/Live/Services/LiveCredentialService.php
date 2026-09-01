<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Live\Services;

use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Infrastructure\Models\SystemSetting;
use MultiTenantSaas\Modules\Infrastructure\Services\TenantSettingService;

/**
 * 直播供给方凭证解析（双轨：租户设置 group=live 优先，缺省回退平台 system_setting）
 *
 * 键约定（group=live）：
 * - polyv:     live.polyv.app_id / live.polyv.secret
 * - tencent:   live.tencent.push_domain / live.tencent.play_domain
 *              live.tencent.push_key / live.tencent.play_key（可空=不签名）
 */
class LiveCredentialService
{
    /** @var array<string, array{required: array<string>, optional: array<string>}> */
    public const KEYS = [
        'polyv' => [
            'required' => ['app_id', 'secret'],
            'optional' => [],
        ],
        'tencent' => [
            'required' => ['push_domain', 'play_domain'],
            'optional' => ['push_key', 'play_key'],
        ],
    ];

    public function __construct(private readonly TenantSettingService $settings) {}

    /**
     * 解析供给方凭证（租户级 → 平台级）
     *
     * @return array<string, ?string>
     */
    public function for(string $provider, int $tenantId): array
    {
        $spec = self::KEYS[$provider]
            ?? throw new ServiceUnavailableException("Unknown live provider credentials: {$provider}");

        $values = [];
        foreach ([...$spec['required'], ...$spec['optional']] as $key) {
            $settingKey = "{$provider}.{$key}";
            $values[$key] = $this->settings->get($tenantId, 'live', $settingKey)
                ?? SystemSetting::get('live', $settingKey);
        }

        foreach ($spec['required'] as $key) {
            if (empty($values[$key])) {
                throw new ServiceUnavailableException(
                    "Live provider [{$provider}] credentials missing: live.{$provider}.{$key} not configured, "
                    . 'configure tenant/platform setting (group=live) or use manual provider',
                );
            }
        }

        return $values;
    }
}
