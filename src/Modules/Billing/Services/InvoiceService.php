<?php

namespace MultiTenantSaas\Modules\Billing\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use MultiTenantSaas\Modules\Billing\Models\Invoice;

/**
 * 发票服务
 *
 * 负责发票生成、状态流转、PDF 输出与历史查询。
 *
 * - 发票号规则: INV-{YYYYMM}-{4位序号}，全局唯一，DB 行锁防并发
 * - 状态流转: draft → issued → paid / void；draft → cancelled
 * - 作废发票保留记录不删除
 * - 查询受 BelongsToTenant 全局作用域自动按当前租户隔离
 */
class InvoiceService
{
    /**
     * 创建发票（草稿）
     *
     * @param  array  $data  发票数据，支持键:
     *                       items(明细数组), currency, due_date,
     *                       subscription_id, payment_order_id, tenant_id
     */
    public static function createInvoice(array $data): Invoice
    {
        return DB::transaction(function () use ($data) {
            $items = $data['items'] ?? [];

            [$subtotal, $taxAmount] = static::summarizeItems($items);
            $total = round($subtotal + $taxAmount, 2);

            $invoice = new Invoice;

            if (isset($data['tenant_id'])) {
                $invoice->tenant_id = $data['tenant_id'];
            }

            $invoice->invoice_number = static::nextInvoiceNumber();
            $invoice->subtotal = $subtotal;
            $invoice->tax_amount = $taxAmount;
            $invoice->total = $total;
            $invoice->currency = $data['currency'] ?? config('pay.invoice.default_currency', 'CNY');
            $invoice->status = Invoice::STATUS_DRAFT;
            $invoice->due_date = $data['due_date'] ?? static::defaultDueDate();
            $invoice->subscription_id = $data['subscription_id'] ?? null;
            $invoice->payment_order_id = $data['payment_order_id'] ?? null;
            $invoice->save();

            foreach ($items as $item) {
                $lineAmount = round((float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0), 2);
                $lineTaxRate = (float) ($item['tax_rate'] ?? 0);

                $invoice->items()->create([
                    'description' => $item['description'] ?? '',
                    'quantity' => $item['quantity'] ?? 0,
                    'unit_price' => $item['unit_price'] ?? 0,
                    'amount' => $item['amount'] ?? $lineAmount,
                    'tax_rate' => $lineTaxRate,
                    'tax_amount' => $item['tax_amount'] ?? round($lineAmount * $lineTaxRate, 2),
                    'related_type' => $item['related_type'] ?? null,
                    'related_id' => $item['related_id'] ?? null,
                ]);
            }

            $invoice->load('items');

            return $invoice;
        });
    }

    /**
     * 开具发票 draft → issued
     */
    public static function issueInvoice(int $invoiceId): Invoice
    {
        $invoice = static::findInvoice($invoiceId);

        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            throw new \RuntimeException(trans('payment.invoice_status_invalid'));
        }

        $invoice->status = Invoice::STATUS_ISSUED;
        $invoice->issued_at = now();
        $invoice->save();

        return $invoice;
    }

    /**
     * 标记已付 issued → paid
     */
    public static function markPaid(int $invoiceId): Invoice
    {
        $invoice = static::findInvoice($invoiceId);

        if ($invoice->status !== Invoice::STATUS_ISSUED) {
            throw new \RuntimeException(trans('payment.invoice_status_invalid'));
        }

        $invoice->status = Invoice::STATUS_PAID;
        $invoice->save();

        return $invoice;
    }

    /**
     * 作废发票 issued → void（保留记录不删除，paid 状态不可直接作废）
     */
    public static function voidInvoice(int $invoiceId): Invoice
    {
        $invoice = static::findInvoice($invoiceId);

        if ($invoice->status === Invoice::STATUS_VOID) {
            throw new \RuntimeException(trans('payment.invoice_already_void'));
        }

        if ($invoice->status === Invoice::STATUS_PAID) {
            throw new \RuntimeException(trans('payment.invoice_cannot_void_paid'));
        }

        if ($invoice->status !== Invoice::STATUS_ISSUED) {
            throw new \RuntimeException(trans('payment.invoice_cannot_void'));
        }

        $invoice->status = Invoice::STATUS_VOID;
        $invoice->save();

        return $invoice;
    }

    /**
     * 取消发票 draft → cancelled
     */
    public static function cancelInvoice(int $invoiceId): Invoice
    {
        $invoice = static::findInvoice($invoiceId);

        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            throw new \RuntimeException(trans('payment.invoice_status_invalid'));
        }

        $invoice->status = Invoice::STATUS_CANCELLED;
        $invoice->save();

        return $invoice;
    }

    /**
     * 生成发票 PDF
     *
     * @return string PDF 文件路径
     */
    public static function generatePdf(int $invoiceId): string
    {
        $invoice = static::findInvoice($invoiceId);
        $invoice->load('items', 'tenant');

        $directory = config('pay.invoice.storage_path', storage_path('app/invoices'));
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $outputPath = rtrim($directory, '/') . '/' . $invoice->invoice_number . '.pdf';

        PdfService::generateInvoice([
            'invoice' => $invoice,
            'items' => $invoice->items,
            'tenant' => $invoice->tenant,
        ], $outputPath);

        return $outputPath;
    }

    /**
     * 查询发票列表（按租户/时间/状态筛选）
     *
     * 租户隔离由 Invoice 模型的 BelongsToTenant 全局作用域自动保证。
     *
     * @param  array  $filters  支持键: status, start_date, end_date,
     *                          subscription_id, payment_order_id
     * @return Collection<int, Invoice>
     */
    public static function getInvoices(array $filters = []): Collection
    {
        $query = Invoice::query()->with('items');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['start_date'])) {
            $query->where('created_at', '>=', $filters['start_date']);
        }
        if (! empty($filters['end_date'])) {
            $query->where('created_at', '<', Carbon::parse($filters['end_date'])->addDay());
        }
        if (! empty($filters['subscription_id'])) {
            $query->where('subscription_id', $filters['subscription_id']);
        }
        if (! empty($filters['payment_order_id'])) {
            $query->where('payment_order_id', $filters['payment_order_id']);
        }

        return $query->orderByDesc('created_at')->get();
    }

    /**
     * 生成下一发票号（INV-{YYYYMM}-{4位序号}），全局行锁防并发
     *
     * 必须在事务中调用以使 lockForUpdate 生效；
     * invoice_number 的唯一约束作为并发兜底，即使锁失效也不会产生重复号。
     */
    protected static function nextInvoiceNumber(): string
    {
        $format = config('pay.invoice.number_format', 'INV-{YYYYMM}-{seq}');
        $prefix = str_replace('{YYYYMM}', now()->format('Ym'), $format);
        $prefix = substr($prefix, 0, strpos($prefix, '{seq}'));

        $maxSeq = DB::table('invoices')
            ->where('invoice_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->select(DB::raw('MAX(CAST(SUBSTRING(invoice_number, ' . (strlen($prefix) + 1) . ') AS UNSIGNED)) AS max_seq'))
            ->value('max_seq');

        return $prefix . str_pad((string) (($maxSeq ?? 0) + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * 汇总明细金额与税额
     *
     * @return array{0: float, 1: float} [subtotal, taxAmount]
     */
    protected static function summarizeItems(array $items): array
    {
        $subtotal = 0.0;
        $taxAmount = 0.0;

        foreach ($items as $item) {
            $lineAmount = round((float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0), 2);
            $amount = (float) ($item['amount'] ?? $lineAmount);
            $rate = (float) ($item['tax_rate'] ?? 0);
            $subtotal += $amount;
            $taxAmount += round((float) ($item['tax_amount'] ?? round($amount * $rate, 2)), 2);
        }

        return [round($subtotal, 2), round($taxAmount, 2)];
    }

    /**
     * 默认到期日
     */
    protected static function defaultDueDate(): string
    {
        $days = (int) config('pay.invoice.default_due_days', 30);

        return now()->addDays($days)->toDateString();
    }

    /**
     * 查找发票（受租户作用域隔离）
     */
    protected static function findInvoice(int $invoiceId): Invoice
    {
        $invoice = Invoice::find($invoiceId);

        if (! $invoice) {
            throw new \RuntimeException(trans('payment.invoice_not_found'));
        }

        return $invoice;
    }
}
