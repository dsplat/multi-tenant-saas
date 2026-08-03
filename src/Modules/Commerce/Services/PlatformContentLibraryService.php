<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Commerce\Services;

use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Modules\Commerce\Models\PlatformContent;
use MultiTenantSaas\Modules\Commerce\Models\PlatformContentPack;

/**
 * 平台内容库服务（P3）
 *
 * 内容条目与内容包的管理；getPackSnapshot 供下单时快照展开
 * （防库内容后续变更影响已成交订单的履约）。
 */
class PlatformContentLibraryService
{
    // ========== 内容条目 ==========

    public function createContent(array $data): PlatformContent
    {
        return PlatformContent::create($data);
    }

    public function updateContent(PlatformContent $content, array $data): PlatformContent
    {
        $content->update($data);

        return $content;
    }

    public function publishContent(PlatformContent $content): void
    {
        $content->update(['status' => PlatformContent::STATUS_PUBLISHED]);
    }

    public function retireContent(PlatformContent $content): void
    {
        $content->update(['status' => PlatformContent::STATUS_RETIRED]);
    }

    // ========== 内容包 ==========

    public function createPack(array $data, array $contentIds = []): PlatformContentPack
    {
        $pack = PlatformContentPack::create($data);

        if ($contentIds) {
            $this->attachContents($pack, $contentIds);
        }

        return $pack;
    }

    public function updatePack(PlatformContentPack $pack, array $data): PlatformContentPack
    {
        $pack->update($data);

        return $pack;
    }

    public function retirePack(PlatformContentPack $pack): void
    {
        $pack->update(['status' => PlatformContentPack::STATUS_RETIRED]);
    }

    /**
     * 挂载内容到包（整体替换）
     */
    public function attachContents(PlatformContentPack $pack, array $contentIds): void
    {
        $sync = [];
        foreach (array_values(array_unique(array_map('intval', $contentIds))) as $sort => $contentId) {
            $exists = PlatformContent::find($contentId);
            if (! $exists) {
                throw new DomainException("内容 [{$contentId}] 不存在");
            }
            $sync[$contentId] = ['sort_order' => $sort];
        }

        $pack->contents()->sync($sync);
    }

    /**
     * 下单快照展开：包内已发布内容清单
     *
     * @return array<int, array{content_id: int, title: string, type: string, body: ?string, file_url: ?string, cover_url: ?string}>
     */
    public function getPackSnapshot(int $packId): array
    {
        $pack = PlatformContentPack::find($packId);
        if (! $pack) {
            throw new DomainException("内容包 [{$packId}] 不存在");
        }
        if (! $pack->isActive()) {
            throw new DomainException("内容包 [{$pack->name}] 未上架");
        }

        $snapshot = $pack->contents()
            ->where('platform_contents.status', PlatformContent::STATUS_PUBLISHED)
            ->get()
            ->map(fn (PlatformContent $c) => [
                'content_id' => (int) $c->content_id,
                'title' => $c->title,
                'type' => $c->type,
                'body' => $c->body,
                'file_url' => $c->file_url,
                'cover_url' => $c->cover_url,
            ])
            ->values()
            ->all();

        if (empty($snapshot)) {
            throw new DomainException("内容包 [{$pack->name}] 无可交付的已发布内容");
        }

        return $snapshot;
    }
}
