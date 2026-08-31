<?php

namespace MultiTenantSaas\Modules\Ai\Services;

use MultiTenantSaas\Exceptions\ServiceUnavailableException;

/**
 * 语音评测服务（骨架：用户裁决 10——可加，本次只落底座接口）
 *
 * 面向上层提供语音评测能力（口语作业打分/发音评估，对标鲸打卡语音作业评测），
 * 屏蔽 ASR/评测供应商差异。本期不接具体供应商：
 *  - evaluate() 为统一入口，供应商未配置时明确抛 ServiceUnavailable
 *  - 接入供应商 = PROVIDER_CLASS_MAP 注册一行 + 实现 provider 适配
 *
 * 返回结构（供应商无关，固定契约）：
 *  {
 *    'score'       => 0-100 总分,
 *    'dimensions'  => ['pronunciation' => 0-100, 'fluency' => 0-100, 'integrity' => 0-100],
 *    'transcript'  => '识别文本',
 *    'raw'         => 供应商原始响应,
 *  }
 */
class AiAudioService
{
    /**
     * 提供商标识与实现类的映射表（接入供应商时注册）
     *
     * @var array<string, class-string>
     */
    protected const PROVIDER_CLASS_MAP = [
        // 'example_asr' => ExampleAsrProvider::class,
    ];

    /**
     * 语音评测统一入口
     *
     * @param string      $audioUrl      音频文件地址（Storage FileUpload 产物）
     * @param string      $referenceText 参考文本（跟读/背诵场景）
     * @param array{provider?: string, language?: string} $options
     * @return array{score: int, dimensions: array<string, int>, transcript: string, raw: mixed}
     * @throws ServiceUnavailableException 供应商未配置时
     */
    public function evaluate(string $audioUrl, string $referenceText = '', array $options = []): array
    {
        $providerKey = $this->resolveProvider($options['provider'] ?? null);

        // 供应商适配位：注册后按 PROVIDER_CLASS_MAP 分派
        // return $this->app->make(self::PROVIDER_CLASS_MAP[$providerKey])->evaluate(...);
        throw new ServiceUnavailableException(
            "语音评测供应商未配置（config('ai.audio_eval.provider')），当前为骨架实现：{$providerKey}"
        );
    }

    /**
     * 供应商是否可用（上层可据此决定功能开关）
     */
    public function isAvailable(): bool
    {
        $providerKey = (string) config('ai.audio_eval.provider', '');

        return $providerKey !== '' && isset(self::PROVIDER_CLASS_MAP[$providerKey]);
    }

    /**
     * 解析供应商：显式指定 > 平台配置（未配置时返回空串由调用方抛错）
     */
    private function resolveProvider(?string $explicit): string
    {
        $key = $explicit ?? (string) config('ai.audio_eval.provider', '');

        if ($key !== '' && ! isset(self::PROVIDER_CLASS_MAP[$key])) {
            throw new ServiceUnavailableException("未知语音评测供应商: {$key}");
        }

        return $key;
    }
}
