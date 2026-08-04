<?php

namespace MultiTenantSaas\Modules\Ai\Services\Tool;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Infrastructure\Services\SiteMetadataExtractor;

/**
 * fetch_site_metadata — 抓取指定 URL 的品牌元数据
 *
 * 提取 logo、favicon、站点名称、描述、主题色等，
 * 供 AI 小助手在租户初始化时自动填充品牌配置。
 */
class FetchSiteMetadataTool implements ToolHandlerContract
{
    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $url = trim((string) ($arguments['url'] ?? ''));

        if ($url === '') {
            return ['error' => true, 'message' => 'url 不能为空'];
        }

        // 基本 URL 格式校验
        if (! preg_match('#^https?://#i', $url) && ! preg_match('#^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z]{2,})+#i', $url)) {
            return ['error' => true, 'message' => 'url 格式不正确，请输入有效的网址'];
        }

        try {
            $extractor = app(SiteMetadataExtractor::class);

            return $extractor->extract($url);
        } catch (\Throwable $e) {
            return [
                'error' => true,
                'message' => '抓取失败：' . $e->getMessage(),
                'url' => $url,
            ];
        }
    }
}
