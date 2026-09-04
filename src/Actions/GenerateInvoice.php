<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\Orders\Actions\Concerns\BuildsOrderPdf;
use AIArmada\Orders\Models\Order;
use Illuminate\Support\Str;
use Spatie\LaravelPdf\PdfBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Generate PDF invoice for an order.
 */
final class GenerateInvoice
{
    use BuildsOrderPdf;

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

        return $prefix . $separator . now()->format($dateFormat) . $separator . mb_strtoupper(Str::random($randomLength));
    }

    /**
     * @return array<string, mixed>
     */
    private function documentData(Order $order): array
    {
        return [
            'invoiceNumber' => $this->generateInvoiceNumber($order),
            'invoiceDate' => now(),
            'documentTitle' => 'Invoice',
            'documentNumberLabel' => 'Invoice No:',
            'documentDateLabel' => 'Invoice Date:',
            'documentFooterGreeting' => 'Thank you for your business!',
            'documentFooterNote' => 'For questions about this invoice, please contact us.',
        ];
    }
}
