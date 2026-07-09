<?php

namespace MultiTenantSaas\Modules\Ai\Services\Agent;

/**
 * AI 模型定价映射
 *
 * 单位：人民币元 / 百万 token（与 config/ai.php 的 providers 配置对应）。
 * 可通过 config('ai.pricing') 扩展或覆盖默认定价。
 */
class AiPricing
{
    /**
     * 内置模型定价（元/百万 token）
     *
     * @var array<string, array{input: float, output: float}>
     */
    private const DEFAULT_PRICING = [
        'gpt-4o' => ['input' => 18.0, 'output' => 54.0],
        'gpt-4o-mini' => ['input' => 1.08, 'output' => 4.32],
        'gpt-4-turbo' => ['input' => 72.0, 'output' => 216.0],
        'gpt-3.5-turbo' => ['input' => 3.6, 'output' => 10.8],
        'qwen-plus' => ['input' => 3.6, 'output' => 10.8],
        'qwen-turbo' => ['input' => 1.44, 'output' => 4.32],
    ];

    /**
     * 获取指定模型的定价
     *
     * @param  string  $model  模型名称
     * @return array{input: float, output: float} 每百万 token 的价格（元）
     */
    public static function getModelPricing(string $model): array
    {
        $custom = config('ai.pricing', []);

        if (isset($custom[$model])) {
            return [
                'input' => (float) $custom[$model]['input'],
                'output' => (float) $custom[$model]['output'],
            ];
        }

        if (isset(self::DEFAULT_PRICING[$model])) {
            return self::DEFAULT_PRICING[$model];
        }

        return ['input' => 0.0, 'output' => 0.0];
    }

    /**
     * 计算单次请求的 Token 费用
     *
     * @param  string  $model              模型名称
     * @param  int     $promptTokens       输入 token 数
     * @param  int     $completionTokens   输出 token 数
     * @return float 费用（元）
     */
    public static function calculateCost(string $model, int $promptTokens, int $completionTokens): float
    {
        $pricing = self::getModelPricing($model);

        $inputCost = ($promptTokens / 1_000_000) * $pricing['input'];
        $outputCost = ($completionTokens / 1_000_000) * $pricing['output'];

        return round($inputCost + $outputCost, 6);
    }
}
