<?php

namespace MultiTenantSaas\Modules\Infrastructure\Services;

use Illuminate\Support\Facades\Http;

/**
 * 从指定 URL 抓取站点品牌元数据
 *
 * 提取 logo、favicon、站点名称、描述、主题色等，
 * 用于租户初始化时自动填充品牌配置。
 */
class SiteMetadataExtractor
{
    /**
     * 抓取并解析指定 URL 的品牌元数据
     *
     * @return array{
     *     url: string,
     *     title: string|null,
     *     description: string|null,
     *     logo_url: string|null,
     *     favicon_url: string|null,
     *     og_image: string|null,
     *     primary_color: string|null,
     *     site_name: string|null,
     * }
     */
    public function extract(string $url): array
    {
        $url = $this->normalizeUrl($url);

        $response = Http::timeout(10)
            ->withHeader('User-Agent', 'Mozilla/5.0 (compatible; TenantBot/1.0)')
            ->get($url);

        if (! $response->ok()) {
            return ['url' => $url, 'error' => "HTTP {$response->status()}"];
        }

        $html = $response->body();

        return [
            'url' => $url,
            'title' => $this->extractTitle($html),
            'description' => $this->extractMeta($html, 'description'),
            'site_name' => $this->extractMeta($html, 'og:site_name') ?: $this->extractTitle($html),
            'logo_url' => $this->extractLogo($html, $url),
            'favicon_url' => $this->extractFavicon($html, $url),
            'og_image' => $this->resolveUrl($this->extractMeta($html, 'og:image'), $url),
            'primary_color' => $this->extractThemeColor($html),
        ];
    }

    /**
     * 从 HTML <title> 提取站点名称
     */
    protected function extractTitle(string $html): ?string
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            $title = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));

            return $title !== '' ? $title : null;
        }

        return null;
    }

    /**
     * 提取 meta 标签内容（name 或 property 匹配）
     */
    protected function extractMeta(string $html, string $name): ?string
    {
        // <meta name="description" content="..."> 或 <meta property="og:image" content="...">
        $patterns = [
            '#<meta[^>]+(?:name|property)=["\']' . preg_quote($name, '#') . '["\'][^>]+content=["\']([^"\']*)["\']#is',
            '#<meta[^>]+content=["\']([^"\']*)["\'][^>]+(?:name|property)=["\']' . preg_quote($name, '#') . '["\']#is',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $value = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    /**
     * 提取 Logo（优先 og:logo → schema.org → /logo.svg → /logo.png）
     */
    protected function extractLogo(string $html, string $baseUrl): ?string
    {
        // og:logo
        $logo = $this->extractMeta($html, 'og:logo');
        if ($logo) {
            return $this->resolveUrl($logo, $baseUrl);
        }

        // JSON-LD schema.org logo
        if (preg_match('#"logo"\s*:\s*"([^"]+)"#i', $html, $m)) {
            return $this->resolveUrl($m[1], $baseUrl);
        }

        // 常见路径探测（只返回候选，不逐一请求）；长路径优先，边界匹配避免子串误命中
        $candidates = ['/images/logo.svg', '/images/logo.png', '/assets/logo.svg', '/assets/logo.png', '/logo.svg', '/logo.png'];
        $base = parse_url($baseUrl, PHP_URL_SCHEME) . '://' . parse_url($baseUrl, PHP_URL_HOST);

        foreach ($candidates as $candidate) {
            // 检查 HTML 中是否引用了该路径（前面不能紧跟字母/数字/斜杠，避免 /images/logo.png 误命中 /logo.png）
            if (preg_match('#(?<![a-zA-Z0-9/])' . preg_quote($candidate, '#') . '#', $html)) {
                return $base . $candidate;
            }
        }

        // 回退到 og:image
        return $this->extractMeta($html, 'og:image')
            ? $this->resolveUrl($this->extractMeta($html, 'og:image'), $baseUrl)
            : null;
    }

    /**
     * 提取 Favicon（link rel="icon" / "shortcut icon" / apple-touch-icon）
     */
    protected function extractFavicon(string $html, string $baseUrl): ?string
    {
        // 优先找 apple-touch-icon（通常质量更高）
        $patterns = [
            '#<link[^>]+rel=["\'][^"\']*apple-touch-icon[^"\']*["\'][^>]+href=["\']([^"\']+)["\']#is',
            '#<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\'][^"\']*apple-touch-icon[^"\']*["\']#is',
            '#<link[^>]+rel=["\'][^"\']*(?:shortcut\s+)?icon[^"\']*["\'][^>]+href=["\']([^"\']+)["\']#is',
            '#<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\'][^"\']*(?:shortcut\s+)?icon[^"\']*["\']#is',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                return $this->resolveUrl(trim($m[1]), $baseUrl);
            }
        }

        // 默认 /favicon.ico
        $base = parse_url($baseUrl, PHP_URL_SCHEME) . '://' . parse_url($baseUrl, PHP_URL_HOST);

        return $base . '/favicon.ico';
    }

    /**
     * 提取主题色（meta theme-color）
     */
    protected function extractThemeColor(string $html): ?string
    {
        $color = $this->extractMeta($html, 'theme-color');
        if ($color && preg_match('/^#[0-9a-fA-F]{3,8}$/', trim($color))) {
            return trim($color);
        }

        return null;
    }

    /**
     * URL 规范化（补 scheme）
     */
    protected function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        return $url;
    }

    /**
     * 相对 URL → 绝对 URL
     */
    protected function resolveUrl(?string $url, string $baseUrl): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        // 已是绝对 URL
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        // 协议相对 //example.com/foo
        if (str_starts_with($url, '//')) {
            return parse_url($baseUrl, PHP_URL_SCHEME) . ':' . $url;
        }

        $base = parse_url($baseUrl, PHP_URL_SCHEME) . '://' . parse_url($baseUrl, PHP_URL_HOST);
        $basePath = parse_url($baseUrl, PHP_URL_PATH) ?? '/';

        // 绝对路径 /foo/bar
        if (str_starts_with($url, '/')) {
            return $base . $url;
        }

        // 相对路径：拼接 base path 目录
        $dir = rtrim(dirname($basePath), '/');

        return $base . $dir . '/' . $url;
    }
}
