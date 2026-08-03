<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Modules\Commerce\Models\CommerceSku;
use MultiTenantSaas\Modules\Commerce\Models\PlatformContent;
use MultiTenantSaas\Modules\Commerce\Models\PlatformContentPack;
use MultiTenantSaas\Modules\Commerce\Models\SupplyGrant;
use MultiTenantSaas\Modules\Commerce\Services\CommerceFulfillmentService;
use MultiTenantSaas\Modules\Commerce\Services\CommerceOrderService;
use MultiTenantSaas\Modules\Commerce\Services\PlatformContentLibraryService;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Scopes\TenantScope;
use MultiTenantSaas\Tests\Schema\BillingModule;
use MultiTenantSaas\Tests\Schema\CommerceModule;
use MultiTenantSaas\Tests\Schema\EventModule;
use MultiTenantSaas\Tests\Schema\WebhookModule;

/**
 * 平台内容库测试（Phase 3）
 *
 * 覆盖：内容/包 CRUD、快照展开（仅已发布）、下单快照嵌入、下架联动失效授权
 */
class PlatformContentLibraryTest extends TestCase
{
    protected array $uses = [CommerceModule::class, BillingModule::class, EventModule::class, WebhookModule::class];

    protected PlatformContentLibraryService $library;

    protected function setUp(): void
    {
        parent::setUp();

        $this->library = $this->app->make(PlatformContentLibraryService::class);

        Tenant::create([
            'tenant_id' => 2005,
            'name' => 'Library Tenant',
            'slug' => 'library-tenant',
            'status' => 'active',
            'subscription_plan' => 'free',
        ]);

        TenantContext::setTenantId('2005');
    }

    private function createPublishedContent(string $title = '内容A'): PlatformContent
    {
        $content = $this->library->createContent(['title' => $title, 'type' => 'article', 'body' => "正文-{$title}"]);
        $this->library->publishContent($content);

        return $content;
    }

    private function createActivePack(array $contentIds, string $name = '内容包A'): PlatformContentPack
    {
        $pack = $this->library->createPack(['name' => $name, 'status' => PlatformContentPack::STATUS_ACTIVE], $contentIds);

        return $pack;
    }

    // ========== 内容库管理 ==========

    public function test_content_lifecycle(): void
    {
        $content = $this->library->createContent(['title' => '草稿', 'type' => 'video']);
        $this->assertEquals(PlatformContent::STATUS_DRAFT, $content->fresh()->status); // DB 默认值

        $this->library->publishContent($content);
        $this->assertTrue($content->fresh()->isPublished());

        $this->library->retireContent($content);
        $this->assertEquals(PlatformContent::STATUS_RETIRED, $content->fresh()->status);
    }

    public function test_pack_attach_replaces_contents(): void
    {
        $a = $this->createPublishedContent('A');
        $b = $this->createPublishedContent('B');

        $pack = $this->createActivePack([$a->content_id]);
        $this->assertCount(1, $pack->contents);

        $this->library->attachContents($pack, [$a->content_id, $b->content_id]);
        $this->assertCount(2, $pack->fresh()->contents);
    }

    public function test_attach_unknown_content_rejected(): void
    {
        $pack = $this->createActivePack([]);

        $this->expectException(DomainException::class);
        $this->library->attachContents($pack, [999999]);
    }

    // ========== 快照展开 ==========

    public function test_pack_snapshot_only_published(): void
    {
        $published = $this->createPublishedContent('已发布');
        $draft = $this->library->createContent(['title' => '草稿', 'type' => 'article']);

        $pack = $this->createActivePack([$published->content_id, $draft->content_id]);

        $snapshot = $this->library->getPackSnapshot($pack->pack_id);

        $this->assertCount(1, $snapshot);
        $this->assertEquals('已发布', $snapshot[0]['title']);
        $this->assertEquals('正文-已发布', $snapshot[0]['body']);
    }

    public function test_pack_snapshot_rejects_inactive_or_empty_pack(): void
    {
        $draftPack = $this->library->createPack(['name' => '未上架包']);
        $this->expectException(DomainException::class);
        $this->library->getPackSnapshot($draftPack->pack_id);
    }

    public function test_pack_snapshot_rejects_pack_without_published_content(): void
    {
        $pack = $this->createActivePack([]);

        $this->expectException(DomainException::class);
        $this->library->getPackSnapshot($pack->pack_id);
    }

    // ========== 下单快照嵌入 ==========

    public function test_place_order_embeds_pack_contents_into_snapshot(): void
    {
        $content = $this->createPublishedContent('包内内容');
        $pack = $this->createActivePack([$content->content_id]);

        $sku = CommerceSku::create([
            'name' => '内容分销包SKU',
            'type' => CommerceSku::TYPE_CONTENT_PACK,
            'role' => CommerceSku::ROLE_SUPPLY,
            'lifecycle' => 'grant',
            'fulfill_handler' => 'content_pack',
            'price' => 199.00,
            'payload' => ['pack_id' => $pack->pack_id, 'settlement' => ['mode' => 'prepay']],
            'status' => CommerceSku::STATUS_ACTIVE,
        ]);

        $order = $this->app->make(CommerceOrderService::class)->placeOrder(901, [['sku_id' => $sku->sku_id]]);

        $item = $order->items()->first();
        $this->assertArrayHasKey('contents', $item->payload_snapshot);
        $this->assertCount(1, $item->payload_snapshot['contents']);
        $this->assertEquals('包内内容', $item->payload_snapshot['contents'][0]['title']);
        $this->assertEquals($pack->pack_id, $item->payload_snapshot['pack_id']);
    }

    public function test_place_order_rejects_missing_pack(): void
    {
        $sku = CommerceSku::create([
            'name' => '坏包SKU',
            'type' => CommerceSku::TYPE_CONTENT_PACK,
            'role' => CommerceSku::ROLE_SUPPLY,
            'lifecycle' => 'grant',
            'fulfill_handler' => 'content_pack',
            'price' => 99.00,
            'payload' => ['pack_id' => 999999],
            'status' => CommerceSku::STATUS_ACTIVE,
        ]);

        $this->expectException(DomainException::class);
        $this->app->make(CommerceOrderService::class)->placeOrder(901, [['sku_id' => $sku->sku_id]]);
    }

    // ========== 下架联动 ==========

    public function test_expire_grants_by_sku(): void
    {
        $sku = CommerceSku::create([
            'name' => '待下架SKU',
            'type' => CommerceSku::TYPE_CONTENT_PACK,
            'role' => CommerceSku::ROLE_SUPPLY,
            'lifecycle' => 'grant',
            'fulfill_handler' => 'content_pack',
            'price' => 99.00,
            'payload' => [],
            'status' => CommerceSku::STATUS_ACTIVE,
        ]);

        SupplyGrant::create([
            'tenant_id' => 2005,
            'sku_id' => $sku->sku_id,
            'status' => SupplyGrant::STATUS_ACTIVE,
            'valid_from' => now(),
        ]);

        $expired = $this->app->make(CommerceFulfillmentService::class)->expireGrantsBySku($sku->sku_id);

        $this->assertEquals(1, $expired);

        $grant = SupplyGrant::withoutGlobalScope(TenantScope::class)
            ->where('sku_id', $sku->sku_id)
            ->first();
        $this->assertEquals(SupplyGrant::STATUS_EXPIRED, $grant->status);
    }
}
