<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\Orders\Actions\Concerns\BuildsOrderDocs;
use AIArmada\Orders\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Spatie\LaravelPdf\PdfBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Generate PDF invoice for an order.
 */
final class GenerateInvoice
{
    use BuildsOrderDocs;

    /**
     * Generate and save invoice to a path.
     */
    public function save(Order $order, string $path): string
    {
        $this->buildPdf($order)->save($path);

        return $path;
    }

    /**
     * Generate and download invoice.
     */
    public function download(Order $order): PdfBuilder | StreamedResponse
    {
        if (! $this->hasPdfRuntime()) {
            return $this->downloadOrderHtmlFallback(
                order: $order,
                view: 'orders::pdf.invoice',
                filename: "invoice-{$order->order_number}.html",
                data: $this->documentData($order),
            );
        }

        return $this->buildPdf($order)->download();
    }

    /**
     * Build the PDF builder instance.
     */
    protected function buildPdf(Order $order): PdfBuilder
    {
        return $this->buildOrderPdf(
            order: $order,
            view: 'orders::pdf.invoice',
            filename: "invoice-{$order->order_number}.pdf",
            data: $this->documentData($order),
        );
    }

    /**
     * Generate invoice number.
     */
    protected function generateInvoiceNumber(Order $order): string
    {
        $prefix = config('orders.invoice.prefix', 'INV');
        $separator = config('orders.invoice.separator', '-');
        $dateFormat = config('orders.invoice.date_format', 'Ymd');
        $randomLength = (int) config('orders.invoice.random_length', 6);

        return $prefix . $separator . CarbonImmutable::now()->format($dateFormat) . $separator . mb_strtoupper(Str::random($randomLength));
    }

    /**
     * Resolve the persisted invoice number, minting and storing one on first
     * use so repeated downloads reuse the same number.
     */
    private function resolveInvoiceNumber(Order $order): string
    {
        if (is_string($order->invoice_number) && mb_trim($order->invoice_number) !== '') {
            return $order->invoice_number;
        }

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $order->invoice_number = $this->generateInvoiceNumber($order);

            try {
                $order->save();

                return $order->invoice_number;
            } catch (QueryException $e) {
                $sqlState = (string) $e->getPrevious()?->getCode();

                if ($attempt === 3 || ($sqlState !== '23000' && $sqlState !== '23505')) {
                    throw $e;
                }
            }
        }

        return $order->refresh()->invoice_number;
    }

    /**
     * @return array<string, mixed>
     */
    private function documentData(Order $order): array
    {
        return [
            'invoiceNumber' => $this->resolveInvoiceNumber($order),
            'invoiceDate' => CarbonImmutable::now(),
            'documentTitle' => 'Invoice',
            'documentNumberLabel' => 'Invoice No:',
            'documentDateLabel' => 'Invoice Date:',
            'documentFooterGreeting' => 'Thank you for your business!',
            'documentFooterNote' => 'For questions about this invoice, please contact us.',
        ];
    }
}
