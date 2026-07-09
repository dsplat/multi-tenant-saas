<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Ai\Services\Ai\Providers;

use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Image;

/**
 * DALL-E 图片生成提供商（基于 laravel/ai Image API）
 *
 * 替代原 DalleProvider，使用 laravel/ai SDK 的 Image::of()->generate() 进行图片生成。
 * laravel/ai 升级时只需 composer update，无需修改本适配器代码。
 *
 * 注意：DALL-E 3 不支持图生图与风格迁移，调用此类方法时抛出异常。
 */
class DalleImageProvider
{
    /**
     * 文生图（DALL-E 3 / DALL-E 2）
     */
    public function textToImage(string $model, string $prompt, array $options = []): array
    {
        $size = (string) ($options['size'] ?? config('ai.image.default_size', '1024x1024'));
        $quality = (string) ($options['quality'] ?? 'standard');

        $pending = Image::of($prompt)
            ->size($this->convertSize($size))
            ->quality($quality === 'hd' ? 'high' : 'medium');

        $response = $pending->generate(Lab::OpenAI, $model);

        $images = [];
        foreach ($response->images as $genImage) {
            $images[] = [
                'b64' => $genImage->image,
                'url' => null,
                'content_type' => $genImage->mime(),
                'revised_prompt' => null,
            ];
        }

        return [
            'provider' => 'dalle',
            'model' => $model,
            'images' => $images,
            'usage' => [
                'image_count' => count($images),
                'size' => $size,
            ],
            'raw' => $response->toArray(),
        ];
    }

    /**
     * 图生图 — DALL-E 不支持
     */
    public function imageToImage(string $model, string $imagePath, string $prompt, array $options = []): array
    {
        throw new \RuntimeException(trans('ai.image_operation_not_supported', [
            'provider' => 'dalle',
            'operation' => 'image_to_image',
        ]));
    }

    /**
     * 图片编辑 — DALL-E 不支持（laravel/ai Image API 当前不支持 inpainting）
     */
    public function editImage(string $model, string $imagePath, ?string $maskPath, string $prompt, array $options = []): array
    {
        throw new \RuntimeException(trans('ai.image_operation_not_supported', [
            'provider' => 'dalle',
            'operation' => 'edit_image',
        ]));
    }

    /**
     * 风格迁移 — DALL-E 不支持
     */
    public function styleTransfer(string $model, string $imagePath, string $stylePrompt, array $options = []): array
    {
        throw new \RuntimeException(trans('ai.image_operation_not_supported', [
            'provider' => 'dalle',
            'operation' => 'style_transfer',
        ]));
    }

    /**
     * Convert pixel size (e.g. "1024x1024") to laravel/ai aspect ratio format.
     */
    private function convertSize(string $size): string
    {
        return match ($size) {
            '1792x1024', '1024x1792' => '16:9',
            '1024x1024', '256x256', '512x512' => '1:1',
            default => '1:1',
        };
    }
}
