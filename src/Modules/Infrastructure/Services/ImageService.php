<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use Illuminate\Support\Facades\File;

/**
 * 图片处理服务
 *
 * 基于 PHP GD 扩展的图片操作：缩放、裁剪、缩略图、尺寸查询。
 * 无外部依赖，所有操作在本地完成。
 */
class ImageService
{
    /**
     * 缩放图片。
     *
     * @param  string  $path  图片路径
     * @param  int|null  $width  目标宽度（null = 按高度等比缩放）
     * @param  int|null  $height  目标高度（null = 按宽度等比缩放）
     * @return string 处理后的图片路径
     */
    public function resize(string $path, ?int $width = null, ?int $height = null): string
    {
        $img = $this->loadImage($path);
        $origWidth = imagesx($img);
        $origHeight = imagesy($img);

        if ($width === null && $height === null) {
            imagedestroy($img);

            return $path;
        }

        if ($width === null) {
            $ratio = $height / $origHeight;
            $width = (int) round($origWidth * $ratio);
        } elseif ($height === null) {
            $ratio = $width / $origWidth;
            $height = (int) round($origHeight * $ratio);
        }

        $resized = imagecreatetruecolor($width, $height);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $width, $height, $origWidth, $origHeight);

        $outputPath = $this->outputPath($path, 'resized');
        $this->saveImage($resized, $outputPath, $path);
        imagedestroy($img);
        imagedestroy($resized);

        return $outputPath;
    }

    /**
     * 裁剪图片。
     *
     * @param  string  $path  图片路径
     * @param  int  $width  裁剪宽度
     * @param  int  $height  裁剪高度
     * @param  int  $x  裁剪起点 X
     * @param  int  $y  裁剪起点 Y
     * @return string 处理后的图片路径
     */
    public function crop(string $path, int $width, int $height, int $x = 0, int $y = 0): string
    {
        $img = $this->loadImage($path);

        $cropped = imagecreatetruecolor($width, $height);
        imagecopy($cropped, $img, 0, 0, $x, $y, $width, $height);

        $outputPath = $this->outputPath($path, 'cropped');
        $this->saveImage($cropped, $outputPath, $path);
        imagedestroy($img);
        imagedestroy($cropped);

        return $outputPath;
    }

    /**
     * 生成缩略图（居中裁剪 + 缩放）。
     *
     * @param  string  $path  图片路径
     * @param  int  $width  目标宽度
     * @param  int  $height  目标高度
     * @return string 处理后的图片路径
     */
    public function thumbnail(string $path, int $width, int $height): string
    {
        $img = $this->loadImage($path);
        $origWidth = imagesx($img);
        $origHeight = imagesy($img);

        // 计算居中裁剪区域
        $srcRatio = $origWidth / $origHeight;
        $dstRatio = $width / $height;

        if ($srcRatio > $dstRatio) {
            // 原图更宽，按高度裁剪宽度
            $newHeight = $origHeight;
            $newWidth = (int) round($origHeight * $dstRatio);
            $srcX = (int) round(($origWidth - $newWidth) / 2);
            $srcY = 0;
        } else {
            // 原图更高，按宽度裁剪高度
            $newWidth = $origWidth;
            $newHeight = (int) round($origWidth / $dstRatio);
            $srcX = 0;
            $srcY = (int) round(($origHeight - $newHeight) / 2);
        }

        $thumb = imagecreatetruecolor($width, $height);
        imagecopyresampled($thumb, $img, 0, 0, $srcX, $srcY, $width, $height, $newWidth, $newHeight);

        $outputPath = $this->outputPath($path, 'thumb');
        $this->saveImage($thumb, $outputPath, $path);
        imagedestroy($img);
        imagedestroy($thumb);

        return $outputPath;
    }

    /**
     * 获取图片尺寸。
     *
     * @return array{width: int, height: int, type: string}|null
     */
    public function getDimensions(string $path): ?array
    {
        if (! File::exists($path)) {
            return null;
        }

        $info = getimagesize($path);
        if ($info === false) {
            return null;
        }

        $types = [
            IMAGETYPE_JPEG => 'jpeg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
        ];

        return [
            'width' => $info[0],
            'height' => $info[1],
            'type' => $types[$info[2]] ?? 'unknown',
        ];
    }

    /**
     * 加载图片资源。
     *
     * @return \GdImage
     */
    protected function loadImage(string $path)
    {
        if (! File::exists($path)) {
            throw new \RuntimeException("Image file not found: {$path}");
        }

        $info = getimagesize($path);
        if ($info === false) {
            throw new \RuntimeException("Invalid image file: {$path}");
        }

        return match ($info[2]) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            IMAGETYPE_PNG => imagecreatefrompng($path),
            IMAGETYPE_GIF => imagecreatefromgif($path),
            IMAGETYPE_WEBP => imagecreatefromwebp($path),
            default => throw new \RuntimeException("Unsupported image type: {$info[2]}"),
        };
    }

    /**
     * 保存图片资源到文件。
     */
    protected function saveImage($img, string $path, string $originalPath): void
    {
        File::ensureDirectoryExists(dirname($path));

        $ext = strtolower(pathinfo($originalPath, PATHINFO_EXTENSION));

        match ($ext) {
            'jpg', 'jpeg' => imagejpeg($img, $path, 90),
            'png' => imagepng($img, $path, 6),
            'gif' => imagegif($img, $path),
            'webp' => imagewebp($img, $path, 90),
            default => imagepng($img, $path),
        };
    }

    /**
     * 生成输出文件路径。
     */
    protected function outputPath(string $originalPath, string $suffix): string
    {
        $dir = dirname($originalPath);
        $name = pathinfo($originalPath, PATHINFO_FILENAME);
        $ext = pathinfo($originalPath, PATHINFO_EXTENSION);

        return "{$dir}/{$name}_{$suffix}.{$ext}";
    }
}
