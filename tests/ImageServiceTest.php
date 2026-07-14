<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Modules\Infrastructure\Services\ImageService;

class ImageServiceTest extends TestCase
{
    protected ImageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available');
        }

        $this->service = app(ImageService::class);
    }

    public function test_service_can_be_resolved(): void
    {
        $this->assertInstanceOf(ImageService::class, $this->service);
    }

    public function test_resize_returns_string(): void
    {
        $path = $this->createTestImage(200, 200);
        $result = $this->service->resize($path, 100, 100);
        $this->assertIsString($result);
        $this->assertFileExists($result);
    }

    public function test_resize_maintains_aspect_ratio(): void
    {
        $path = $this->createTestImage(400, 200);
        $result = $this->service->resize($path, 200, null);
        $size = getimagesize($result);
        $this->assertEquals(200, $size[0]);
        $this->assertEquals(100, $size[1]);
    }

    public function test_crop_returns_string(): void
    {
        $path = $this->createTestImage(200, 200);
        $result = $this->service->crop($path, 100, 100, 50, 50);
        $this->assertIsString($result);
        $size = getimagesize($result);
        $this->assertEquals(100, $size[0]);
        $this->assertEquals(100, $size[1]);
    }

    public function test_thumbnail_returns_string(): void
    {
        $path = $this->createTestImage(400, 400);
        $result = $this->service->thumbnail($path, 100, 100);
        $this->assertIsString($result);
        $size = getimagesize($result);
        $this->assertEquals(100, $size[0]);
        $this->assertEquals(100, $size[1]);
    }

    public function test_get_dimensions_returns_array(): void
    {
        $path = $this->createTestImage(300, 200);
        $dims = $this->service->getDimensions($path);
        $this->assertEquals(300, $dims['width']);
        $this->assertEquals(200, $dims['height']);
    }

    public function test_get_dimensions_returns_null_for_invalid(): void
    {
        $dims = $this->service->getDimensions('/nonexistent/file.jpg');
        $this->assertNull($dims);
    }

    /**
     * 创建临时测试图片。
     */
    protected function createTestImage(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        $red = imagecolorallocate($img, 255, 0, 0);
        imagefill($img, 0, 0, $red);

        $path = tempnam(sys_get_temp_dir(), 'img_test_') . '.png';
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }
}
