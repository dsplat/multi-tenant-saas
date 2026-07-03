<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Models\Invoice;
use MultiTenantSaas\Models\InvoiceItem;
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\InvoiceService;
use MultiTenantSaas\Tests\Schema\BillingModule;

class InvoiceServiceTest extends TestCase
{
    protected array $uses = [BillingModule::class];

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'tenant_id' => 1001,
            'name' => 'Invoice Tenant',
            'slug' => 'invoice-tenant',
            'status' => 'active',
        ]);

        TenantContext::setTenantId(1001);
    }

    public function test_create_invoice_with_items(): void
    {
        $invoice = InvoiceService::createInvoice([
            'tenant_id' => 1001,
            'items' => [
                ['description' => 'Item A', 'quantity' => 2, 'unit_price' => 100],
                ['description' => 'Item B', 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 0.1],
            ],
        ]);

        $this->assertEquals('draft', $invoice->status);
        $this->assertEquals(250.00, (float) $invoice->subtotal);
        $this->assertEquals(5.00, (float) $invoice->tax_amount);
        $this->assertEquals(255.00, (float) $invoice->total);
        $this->assertCount(2, $invoice->items);
    }

    public function test_create_invoice_generates_number(): void
    {
        $invoice = InvoiceService::createInvoice([
            'tenant_id' => 1001,
            'items' => [
                ['description' => 'Item', 'quantity' => 1, 'unit_price' => 100],
            ],
        ]);

        $this->assertNotNull($invoice->invoice_number);
        $this->assertStringStartsWith('INV-', $invoice->invoice_number);
    }

    public function test_create_invoice_number_format(): void
    {
        $invoice = InvoiceService::createInvoice([
            'tenant_id' => 1001,
            'items' => [
                ['description' => 'Item', 'quantity' => 1, 'unit_price' => 100],
            ],
        ]);

        $this->assertMatchesRegularExpression('/^INV-\d{6}-\d{4}$/', $invoice->invoice_number);
    }

    public function test_issue_invoice(): void
    {
        $invoice = InvoiceService::createInvoice([
            'tenant_id' => 1001,
            'items' => [
                ['description' => 'Item', 'quantity' => 1, 'unit_price' => 100],
            ],
        ]);

        $issued = InvoiceService::issueInvoice($invoice->invoice_id);

        $this->assertEquals(Invoice::STATUS_ISSUED, $issued->status);
        $this->assertNotNull($issued->issued_at);
    }

    public function test_issue_non_draft_throws(): void
    {
        $invoice = InvoiceService::createInvoice([
            'tenant_id' => 1001,
            'items' => [
                ['description' => 'Item', 'quantity' => 1, 'unit_price' => 100],
            ],
        ]);

        InvoiceService::issueInvoice($invoice->invoice_id);

        $this->expectException(\RuntimeException::class);
        InvoiceService::issueInvoice($invoice->invoice_id);
    }

    public function test_mark_paid(): void
    {
        $invoice = InvoiceService::createInvoice([
            'tenant_id' => 1001,
            'items' => [
                ['description' => 'Item', 'quantity' => 1, 'unit_price' => 100],
            ],
        ]);

        InvoiceService::issueInvoice($invoice->invoice_id);
        $paid = InvoiceService::markPaid($invoice->invoice_id);

        $this->assertEquals(Invoice::STATUS_PAID, $paid->status);
    }

    public function test_mark_paid_non_issued_throws(): void
    {
        $invoice = InvoiceService::createInvoice([
            'tenant_id' => 1001,
            'items' => [
                ['description' => 'Item', 'quantity' => 1, 'unit_price' => 100],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        InvoiceService::markPaid($invoice->invoice_id);
    }

    public function test_void_invoice(): void
    {
        $invoice = InvoiceService::createInvoice([
            'tenant_id' => 1001,
            'items' => [
                ['description' => 'Item', 'quantity' => 1, 'unit_price' => 100],
            ],
        ]);

        InvoiceService::issueInvoice($invoice->invoice_id);
        $voided = InvoiceService::voidInvoice($invoice->invoice_id);

        $this->assertEquals(Invoice::STATUS_VOID, $voided->status);
    }

    public function test_void_draft_invoice_throws(): void
    {
        $invoice = InvoiceService::createInvoice([
            'tenant_id' => 1001,
            'items' => [
                ['description' => 'Item', 'quantity' => 1, 'unit_price' => 100],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        InvoiceService::voidInvoice($invoice->invoice_id);
    }

    public function test_void_paid_invoice_throws(): void
    {
        $invoice = InvoiceService::createInvoice([
            'tenant_id' => 1001,
            'items' => [
                ['description' => 'Item', 'quantity' => 1, 'unit_price' => 100],
            ],
        ]);

        InvoiceService::issueInvoice($invoice->invoice_id);
        InvoiceService::markPaid($invoice->invoice_id);

        $this->expectException(\RuntimeException::class);
        InvoiceService::voidInvoice($invoice->invoice_id);
    }

    public function test_void_already_void_invoice_throws(): void
    {
        $invoice = InvoiceService::createInvoice([
            'tenant_id' => 1001,
            'items' => [
                ['description' => 'Item', 'quantity' => 1, 'unit_price' => 100],
            ],
        ]);

        InvoiceService::issueInvoice($invoice->invoice_id);
        InvoiceService::voidInvoice($invoice->invoice_id);

        $this->expectException(\RuntimeException::class);
        InvoiceService::voidInvoice($invoice->invoice_id);
    }

    public function test_cancel_invoice(): void
    {
        $invoice = InvoiceService::createInvoice([
            'tenant_id' => 1001,
            'items' => [
                ['description' => 'Item', 'quantity' => 1, 'unit_price' => 100],
            ],
        ]);

        $cancelled = InvoiceService::cancelInvoice($invoice->invoice_id);

        $this->assertEquals(Invoice::STATUS_CANCELLED, $cancelled->status);
    }

    public function test_cancel_non_draft_throws(): void
    {
        $invoice = InvoiceService::createInvoice([
            'tenant_id' => 1001,
            'items' => [
                ['description' => 'Item', 'quantity' => 1, 'unit_price' => 100],
            ],
        ]);

        InvoiceService::issueInvoice($invoice->invoice_id);

        $this->expectException(\RuntimeException::class);
        InvoiceService::cancelInvoice($invoice->invoice_id);
    }

    public function test_get_invoices_includes_items(): void
    {
        InvoiceService::createInvoice([
            'tenant_id' => 1001,
            'items' => [
                ['description' => 'Item', 'quantity' => 1, 'unit_price' => 100],
            ],
        ]);

        $invoices = InvoiceService::getInvoices();

        $this->assertCount(1, $invoices);
        $this->assertTrue($invoices->first()->relationLoaded('items'));
    }

    public function test_get_invoices_returns_empty_for_missing(): void
    {
        $invoices = InvoiceService::getInvoices(['status' => 'nonexistent']);

        $this->assertCount(0, $invoices);
    }

    public function test_list_returns_all(): void
    {
        for ($i = 0; $i < 3; $i++) {
            InvoiceService::createInvoice([
                'tenant_id' => 1001,
                'items' => [
                    ['description' => "Item $i", 'quantity' => 1, 'unit_price' => 100],
                ],
            ]);
        }

        $result = InvoiceService::getInvoices();

        $this->assertCount(3, $result);
    }

    public function test_list_filter_by_status(): void
    {
        $invoice = InvoiceService::createInvoice([
            'tenant_id' => 1001,
            'items' => [
                ['description' => 'Item', 'quantity' => 1, 'unit_price' => 100],
            ],
        ]);

        InvoiceService::issueInvoice($invoice->invoice_id);
        InvoiceService::createInvoice([
            'tenant_id' => 1001,
            'items' => [
                ['description' => 'Item 2', 'quantity' => 1, 'unit_price' => 50],
            ],
        ]);

        $drafts = InvoiceService::getInvoices(['status' => Invoice::STATUS_DRAFT]);
        $issued = InvoiceService::getInvoices(['status' => Invoice::STATUS_ISSUED]);

        $this->assertCount(1, $drafts);
        $this->assertCount(1, $issued);
    }

    public function test_create_invoice_with_morph_relation(): void
    {
        $invoice = InvoiceService::createInvoice([
            'tenant_id' => 1001,
            'items' => [
                [
                    'description' => 'Related Item',
                    'quantity' => 1,
                    'unit_price' => 100,
                    'related_type' => 'App\\Models\\Order',
                    'related_id' => 42,
                ],
            ],
        ]);

        $item = $invoice->items->first();
        $this->assertEquals('App\\Models\\Order', $item->related_type);
        $this->assertEquals(42, $item->related_id);
    }

    public function test_generate_pdf(): void
    {
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            $this->markTestSkipped('barryvdh/laravel-dompdf not installed');
        }

        if (! app()->bound('dompdf.wrapper')) {
            $this->markTestSkipped('dompdf.wrapper binding not registered in test environment');
        }

        $invoice = InvoiceService::createInvoice([
            'tenant_id' => 1001,
            'items' => [
                ['description' => 'Item', 'quantity' => 1, 'unit_price' => 100],
            ],
        ]);

        $path = InvoiceService::generatePdf($invoice->invoice_id);

        $this->assertIsString($path);
        $this->assertStringEndsWith('.pdf', $path);
    }
}
