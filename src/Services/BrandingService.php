<?php

namespace MultiTenantSaas\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MultiTenantSaas\Models\BrandingConfig;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 白标品牌服务
 *
 * 职责：
 * - 租户自定义 Logo / favicon 上传（基于现有 FileService，支持 CDN 磁盘）
 * - 主色调 / 辅助色配置
 * - 自定义域名绑定
 * - 登录页样式与邮件模板品牌化
 * - 自定义 CSS 注入
 *
 * 注意：本服务按显式 tenant_id 操作，绕过 TenantScope，
 * 安全由调用方（Controller/Service）保证用户有权访问目标租户。
 */
class BrandingService
{
    /**
     * 获取租户品牌配置（不存在则创建默认配置）
     */
    public function getConfig(int $tenantId): BrandingConfig
    {
        $config = BrandingConfig::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($config !== null) {
            return $config;
        }

        return BrandingConfig::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenantId,
            'primary_color' => config('tenancy.branding.default_primary_color'),
            'secondary_color' => config('tenancy.branding.default_secondary_color'),
            'login_page_style' => config('tenancy.branding.default_login_page_style'),
            'email_template' => config('tenancy.branding.default_email_template'),
        ]);
    }

    /**
     * 更新品牌配置
     *
     * @param  array<string, mixed>  $data
     */
    public function updateConfig(int $tenantId, array $data): BrandingConfig
    {
        $config = $this->getConfig($tenantId);

        $config->fill($data)->save();

        return $config->fresh();
    }

    /**
     * 上传 Logo
     *
     * @throws \RuntimeException 文件格式或大小不符时抛出
     */
    public function uploadLogo(int $tenantId, UploadedFile $file): BrandingConfig
    {
        $this->validateImage($file);

        $url = $this->storeAsset($tenantId, $file, 'logo');

        return $this->updateConfig($tenantId, ['logo_url' => $url]);
    }

    /**
     * 上传 Favicon
     *
     * @throws \RuntimeException 文件格式或大小不符时抛出
     */
    public function uploadFavicon(int $tenantId, UploadedFile $file): BrandingConfig
    {
        $this->validateImage($file);

        $url = $this->storeAsset($tenantId, $file, 'favicon');

        return $this->updateConfig($tenantId, ['favicon_url' => $url]);
    }

    /**
     * 设置主色调与辅助色
     */
    public function setColors(int $tenantId, string $primary, ?string $secondary = null): BrandingConfig
    {
        $data = ['primary_color' => $primary];
        if ($secondary !== null) {
            $data['secondary_color'] = $secondary;
        }

        return $this->updateConfig($tenantId, $data);
    }

    /**
     * 设置自定义 CSS
     */
    public function setCustomCss(int $tenantId, string $css): BrandingConfig
    {
        return $this->updateConfig($tenantId, ['custom_css' => $css]);
    }

    /**
     * 设置登录页样式
     */
    public function setLoginPageStyle(int $tenantId, string $style): BrandingConfig
    {
        return $this->updateConfig($tenantId, ['login_page_style' => $style]);
    }

    /**
     * 设置邮件模板品牌化
     */
    public function setEmailTemplate(int $tenantId, string $template): BrandingConfig
    {
        return $this->updateConfig($tenantId, ['email_template' => $template]);
    }

    /**
     * 绑定自定义域名
     *
     * @throws \RuntimeException 域名格式无效或已被占用时抛出
     */
    public function setCustomDomain(int $tenantId, string $domain): BrandingConfig
    {
        if (! config('tenancy.branding.custom_domain_enabled', true)) {
            throw new \RuntimeException(trans('tenant.branding_domain_invalid'));
        }

        $domain = strtolower(trim($domain));

        if (! $this->isValidDomain($domain)) {
            throw new \RuntimeException(trans('tenant.branding_domain_invalid'));
        }

        $inUse = BrandingConfig::withoutGlobalScope(TenantScope::class)
            ->where('custom_domain', $domain)
            ->where('tenant_id', '!=', $tenantId)
            ->exists();

        if ($inUse) {
            throw new \RuntimeException(trans('tenant.branding_domain_in_use'));
        }

        return $this->updateConfig($tenantId, ['custom_domain' => $domain]);
    }

    /**
     * 通过自定义域名解析租户品牌配置
     */
    public function resolveDomain(string $domain): ?BrandingConfig
    {
        $domain = strtolower(trim($domain));

        return BrandingConfig::withoutGlobalScope(TenantScope::class)
            ->where('custom_domain', $domain)
            ->first();
    }

    /**
     * 获取邮件品牌化数据
     *
     * @return array<string, mixed>
     */
    public function getEmailBranding(int $tenantId): array
    {
        $config = $this->getConfig($tenantId);

        return [
            'logo_url' => $config->logo_url,
            'primary_color' => $config->primary_color ?? config('tenancy.branding.default_primary_color'),
            'secondary_color' => $config->secondary_color ?? config('tenancy.branding.default_secondary_color'),
            'app_name' => config('app.name'),
            'login_page_style' => $config->login_page_style,
            'email_template' => $config->email_template,
        ];
    }

    /**
     * 渲染品牌化邮件模板
     *
     * 在不引入 Blade 视图的前提下，返回带品牌头部/尾部的 HTML。
     */
    public function renderEmailTemplate(int $tenantId, string $content, array $data = []): string
    {
        $brand = $this->getEmailBranding($tenantId);

        $logoHtml = $brand['logo_url']
            ? '<img src="' . e((string) $brand['logo_url']) . '" alt="Logo" height="40">'
            : e((string) $brand['app_name']);

        $primary = e((string) $brand['primary_color']);
        $secondary = e((string) $brand['secondary_color']);

        return '<div style="font-family:sans-serif;color:#333;max-width:600px;margin:0 auto;">'
            . '<div style="background:' . $primary . ';padding:16px;text-align:center;">' . $logoHtml . '</div>'
            . '<div style="padding:16px;">' . $content . '</div>'
            . '<div style="background:' . $secondary . ';padding:12px;color:#fff;font-size:12px;text-align:center;">'
            . '&copy; ' . date('Y') . ' ' . e((string) $brand['app_name'])
            . '</div>'
            . '</div>';
    }

    /**
     * 验证图片文件格式与大小
     *
     * @throws \RuntimeException
     */
    private function validateImage(UploadedFile $file): void
    {
        $mime = $file->getMimeType();
        $allowed = config('tenancy.branding.logo_mime_types', []);
        $maxSize = (int) config('tenancy.branding.logo_max_size', 2097152);

        if (! empty($allowed) && ! in_array($mime, $allowed, true)) {
            throw new \RuntimeException(trans('tenant.branding_logo_invalid'));
        }

        if ($file->getSize() > $maxSize) {
            throw new \RuntimeException(trans('tenant.branding_logo_too_large'));
        }
    }

    /**
     * 存储品牌资产文件并返回可访问 URL
     *
     * 优先通过 FileService 上传（含配额检查与 DB 记录），
     * 回退到直接写入品牌化磁盘。
     */
    private function storeAsset(int $tenantId, UploadedFile $file, string $category): string
    {
        if (class_exists(FileService::class)) {
            $upload = FileService::upload($file, $tenantId, null, 'branding', null, true);

            return FileService::getUrl($upload);
        }

        $disk = config('tenancy.file_storage_disk', 'local');
        $extension = $file->getClientOriginalExtension();
        $storedName = Str::uuid() . ($extension ? '.' . $extension : '');
        $path = "uploads/{$tenantId}/branding/{$category}/{$storedName}";

        $file->storeAs('', $path, $disk);

        return Storage::disk($disk)->url($path);
    }

    /**
     * 校验域名格式
     */
    private function isValidDomain(string $domain): bool
    {
        if ($domain === '' || strlen($domain) > 200) {
            return false;
        }

        return (bool) preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/i', $domain);
    }
}
