<?php

namespace MultiTenantSaas\Modules\Ai\Services;

use Illuminate\Contracts\Container\Container;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;
use MultiTenantSaas\Modules\Ai\Services\Ai\Providers\QwenAudioEvalProvider;

/**
 * 语音评测服务
 *
 * 面向上层提供语音评测能力（口语作业打分/发音评估，对标鲸打卡语音作业评测），
 * 屏蔽 ASR/评测供应商差异。
 *
 * 已接入：
 *  - bailian：阿里云百炼 qwen 两段式（qwen3-asr-flash 转写 + qwen3.5-omni-flash 评分），
 *    复用小程序/小秘书 bailian 供应商的 base_url/api_key 链路。
 *
 * 后续接入新供应商 = PROVIDER_CLASS_MAP 注册一行 + 实现 provider 适配
 * （参照 QwenAudioEvalProvider 的 evaluate(audioUrl, referenceText, options) 契约）。
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
     * 提供商标识与实现类的映射表
     *
     * @var array<string, class-string>
     */
    protected const PROVIDER_CLASS_MAP = [
        'bailian' => QwenAudioEvalProvider::class,
    ];

    public function __construct(private readonly Container $app) {}

    /**
     * 语音评测统一入口
     *
     * @param string      $audioUrl      音频文件地址（公开可访问 URL / Storage 产物）
     * @param string      $referenceText 参考文本（跟读/背诵场景）
     * @param array{provider?: string, language?: string, transcribe_model?: string, scoring_model?: string} $options
     * @return array{score: int, dimensions: array<string, int>, transcript: string, raw: mixed}
     * @throws ServiceUnavailableException 供应商未配置/未知时
     */
    public function evaluate(string $audioUrl, string $referenceText = '', array $options = []): array
    {
        $providerKey = $this->resolveProvider($options['provider'] ?? null);

        if ($providerKey === '') {
            throw new ServiceUnavailableException(
                "语音评测供应商未配置（config('ai.audio_eval.provider')），请设置 AI_AUDIO_EVAL_PROVIDER"
            );
        }

        $config = AiPlatformConfigService::resolveProviderConfig($providerKey);
        $config['driver'] ??= $providerKey;

        $apiKey = (string) (($config['api_key'] ?? '') ?: ($config['key'] ?? ''));
        if ($apiKey === '') {
            throw new ServiceUnavailableException(
                "语音评测供应商 [{$providerKey}] 未配置 API Key（AI_BAILIAN_API_KEY）"
            );
        }

        /** @var QwenAudioEvalProvider $provider */
        $provider = new (self::PROVIDER_CLASS_MAP[$providerKey])($config);

        return $provider->evaluate($audioUrl, $referenceText, $options);
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