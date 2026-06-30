<?php

namespace MultiTenantSaas\Enums;

/**
 * AI 模型枚举
 *
 * 统一枚举系统接入的 AI 模型，标注类型、提供商、默认 max_tokens 与废弃标记，
 * 供 AiGatewayService 做模型路由、配额校验与计费参考。
 *
 * 模型 value 与各提供商 API 实际接受的 model 字符串保持一致。
 */
enum AiModelEnum: string
{
    // ---- 文本模型 ----
    case Gpt4o = 'gpt-4o';
    case Gpt4oMini = 'gpt-4o-mini';
    case Gpt4Turbo = 'gpt-4-turbo';
    case Claude35Sonnet = 'claude-3-5-sonnet';
    case Glm4 = 'glm-4';
    case Glm4Flash = 'glm-4-flash';
    case DeepSeekV3 = 'deepseek-chat';

    // ---- 图片模型 ----
    case DallE3 = 'dall-e-3';
    case Sdxl = 'sdxl';

    // ---- 视频模型 ----
    case RunwayGen3 = 'gen3-alpha';
    case Kling = 'kling-v1';

    /**
     * 模型类型
     *
     * @return string text|image|video
     */
    public function type(): string
    {
        return match ($this) {
            self::Gpt4o, self::Gpt4oMini, self::Gpt4Turbo,
            self::Claude35Sonnet, self::Glm4, self::Glm4Flash,
            self::DeepSeekV3 => 'text',
            self::DallE3, self::Sdxl => 'image',
            self::RunwayGen3, self::Kling => 'video',
        };
    }

    /**
     * 提供商标识
     *
     * 返回值对应 config/ai.php 中 providers 键名。
     */
    public function provider(): string
    {
        return match ($this) {
            self::Gpt4o, self::Gpt4oMini, self::Gpt4Turbo, self::DallE3 => 'openai',
            self::Claude35Sonnet => 'anthropic',
            self::Glm4, self::Glm4Flash => 'zhipu',
            self::DeepSeekV3 => 'deepseek',
            self::Sdxl => 'stability',
            self::RunwayGen3 => 'runway',
            self::Kling => 'kuaishou',
        };
    }

    /**
     * 默认 max_tokens
     *
     * 仅文本模型有意义；图片/视频模型返回 0。
     */
    public function defaultMaxTokens(): int
    {
        return match ($this) {
            self::Gpt4o => 4096,
            self::Gpt4oMini => 16384,
            self::Gpt4Turbo => 4096,
            self::Claude35Sonnet => 8192,
            self::Glm4 => 8192,
            self::Glm4Flash => 8192,
            self::DeepSeekV3 => 8192,
            self::DallE3, self::Sdxl, self::RunwayGen3, self::Kling => 0,
        };
    }

    /**
     * 是否已废弃
     *
     * TODO: implement deprecation tracking — 当前所有模型均返回 false，
     * 待引入 deprecated 属性或状态字段后再做真实判断。
     */
    public function isDeprecated(): bool
    {
        return match ($this) {
            default => false,
        };
    }

    /**
     * 按提供商筛选模型
     *
     * @param  string  $provider  提供商标识
     * @return self[]
     */
    public static function forProvider(string $provider): array
    {
        return array_filter(
            self::cases(),
            fn (self $model) => $model->provider() === $provider,
        );
    }

    /**
     * 按类型筛选模型
     *
     * @param  string  $type  text|image|video
     * @return self[]
     */
    public static function forType(string $type): array
    {
        return array_filter(
            self::cases(),
            fn (self $model) => $model->type() === $type,
        );
    }
}
