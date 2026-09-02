<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Ai\Providers;

use Illuminate\Support\Str;
use MultiTenantSaas\Exceptions\DomainException;

/**
 * 语音评测 Provider（阿里云百炼 qwen 两段式）
 *
 * 第一段：qwen3-asr-flash 转写（OpenAI 兼容 chat/completions + input_audio 公网 URL）
 * 第二段：qwen3.5-omni-flash 全模态模型按参考文本评分（纯文本输入，输出 JSON）
 *
 * 端点/钥匙复用 config('ai.providers.bailian')（小秘书 bailian 供应商同一链路），
 * 无需独立供应商；音频输入 ≤10MB（base64 形态限制），URL 需公网可访问。
 *
 * 返回结构固定契约（AiAudioService::evaluate）：
 *  {
 *    'score'       => 0-100 总分,
 *    'dimensions'  => ['pronunciation' => 0-100, 'fluency' => 0-100, 'integrity' => 0-100],
 *    'transcript'  => '识别文本',
 *    'raw'         => 两次响应的原始响应,
 *  }
 */
class QwenAudioEvalProvider extends LaravelAiProviderAdapter
{
    /**
     * 两段式评测统一入口：转写 → 评分
     *
     * @param  array{transcribe_model?: string, scoring_model?: string, timeout?: int}  $options
     */
    public function evaluate(string $audioUrl, string $referenceText = '', array $options = []): array
    {
        $transcribeResult = $this->rawChatCompletion(
            (string) ($options['transcribe_model'] ?? config('ai.audio_eval.transcribe_model', 'qwen3-asr-flash')),
            [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_audio', 'input_audio' => ['data' => $audioUrl]],
                ],
            ]],
            [],
            (int) ($options['timeout'] ?? config('ai.timeout', 60)),
        );

        $transcript = trim((string) ($transcribeResult['content'] ?? ''));
        if ($transcript === '') {
            throw new DomainException('语音转写返回空文本（qwen3-asr-flash）');
        }

        $scoreResult = $this->rawChatCompletion(
            (string) ($options['scoring_model'] ?? config('ai.audio_eval.scoring_model', 'qwen3.5-omni-flash')),
            $this->buildScoringMessages($transcript, $referenceText),
            ['temperature' => 0],
            (int) ($options['timeout'] ?? config('ai.timeout', 60)),
        );

        [$score, $dimensions] = $this->parseScoreJson((string) ($scoreResult['content'] ?? ''));

        return [
            'score' => $score,
            'dimensions' => $dimensions,
            'transcript' => $transcript,
            'raw' => [
                'transcribe' => $transcribeResult['raw'] ?? null,
                'score' => $scoreResult['raw'] ?? null,
            ],
        ];
    }

    /** 组装评分提示词（有参考文本=跟读比对；无参考文本=朗读质量） */
    protected function buildScoringMessages(string $transcript, string $referenceText): array
    {
        $system = '你是语音评测专家。请对学习者朗读音频的识别文本进行评测打分：'
            . '发音准确度 pronunciation（0-100 整数）、流利度 fluency（0-100 整数）、'
            . '内容完整度 integrity（0-100 整数）、综合总分 score（0-100 整数）。'
            . '只输出 JSON，不要输出其他内容：'
            . '{"score":0,"dimensions":{"pronunciation":0,"fluency":0,"integrity":0}}';

        $user = $referenceText !== ''
            ? "参考文本：{$referenceText}\n\n学习者朗读识别文本：{$transcript}"
            : "学习者朗读识别文本（无参考文本，按朗读质量评测）：{$transcript}";

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * 解析模型输出的评分 JSON（容错：代码块/前后附注，截取首个 { 至末个 }）
     *
     * @return array{0: int, 1: array<string, int>} [score, dimensions]
     */
    protected function parseScoreJson(string $content): array
    {
        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if ($start === false || $end === false || $end <= $start) {
            throw new DomainException('语音评测评分响应解析失败（未找到 JSON）: '.Str::limit($content, 200));
        }

        $data = json_decode(substr($content, $start, $end - $start + 1), true);
        if (! is_array($data)) {
            throw new DomainException('语音评测评分响应解析失败（JSON 无效）: '.Str::limit($content, 200));
        }

        $dimensions = array_merge(
            ['pronunciation' => 0, 'fluency' => 0, 'integrity' => 0],
            array_map('intval', (array) ($data['dimensions'] ?? [])),
        );

        // 未给总分时按三维均值兜底
        $score = isset($data['score'])
            ? (int) $data['score']
            : (int) round(array_sum($dimensions) / count($dimensions));

        return [$score, $dimensions];
    }
}