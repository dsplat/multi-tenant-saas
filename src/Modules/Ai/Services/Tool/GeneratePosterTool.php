<?php

namespace MultiTenantSaas\Modules\Ai\Services\Tool;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Ai\Services\AiImageService;
use Throwable;

/**
 * generate_poster — 按文案描述生成海报图片
 *
 * 供设计制作/营销策划等数字员工产出活动海报：
 * 调 AiImageService 文生图（默认套餐内 qwen-image-2.0），
 * 生成图自动经 FileService 落盘 FileUpload，返回图片 URL 供消息卡片展示。
 *
 * 出图失败时降级返回海报文案+设计说明（不阻断对话流程）。
 */
class GeneratePosterTool implements ToolHandlerContract
{
    /**
     * 默认海报模型（Token Plan 套餐内）
     */
    protected const DEFAULT_MODEL = 'qwen-image-2.0';

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $prompt = trim((string) ($arguments['prompt'] ?? ''));

        if ($prompt === '') {
            return ['error' => true, 'message' => 'prompt 不能为空，请描述海报的主题、文案与风格'];
        }

        $options = [
            'model' => trim((string) ($arguments['model'] ?? '')) ?: self::DEFAULT_MODEL,
        ];

        if (! empty($arguments['size']) && is_string($arguments['size'])) {
            $options['size'] = $arguments['size'];
        }

        try {
            $result = app(AiImageService::class)->textToImage($prompt, $options);
        } catch (Throwable $e) {
            // 出图受阻时降级为文案产出，引导用户改用设计说明
            return [
                'error' => true,
                'degraded' => true,
                'message' => "海报图片生成失败：{$e->getMessage()}",
                'suggestion' => '可先基于以下描述人工制作，或稍后重试出图',
                'poster_brief' => $prompt,
            ];
        }

        $images = array_map(fn (array $image) => [
            'file_upload_id' => (string) $image['file_upload_id'],
            'url' => $image['url'],
            'mime_type' => $image['mime_type'],
        ], $result['images']);

        return [
            'success' => true,
            'model' => $result['model'],
            'images' => $images,
            'message' => '海报已生成，共 ' . count($images) . ' 张，可在消息卡片中预览或下载。',
        ];
    }
}
