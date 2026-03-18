<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\Orders\Models\Order;
use Illuminate\Support\Str;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Generate PDF invoice for an order.
 */
final class GenerateInvoice
{
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
     *
     * @return StreamedResponse
     */
    public function download(Order $order)
    {
        return $this->buildPdf($order)->download();
    }

    /**
     * Build the PDF builder instance.
     */
    protected function buildPdf(Order $order): PdfBuilder
    {
        $invoiceNumber = $this->generateInvoiceNumber($order);

        $pdf = Pdf::view('orders::pdf.invoice', [
            'order' => $order,
            'items' => $order->items,
            'billingAddress' => $order->billingAddress,
            'shippingAddress' => $order->shippingAddress,
            'payments' => $order->payments()->where('status', 'completed')->get(),
            'invoiceNumber' => $invoiceNumber,
            'invoiceDate' => now(),
        ])
            ->format('a4')
            ->margins(15, 15, 15, 15)
            ->name("invoice-{$order->order_number}.pdf");

        // Configure node module path for puppeteer
        $nodeModulePath = base_path('node_modules');
        if (is_dir($nodeModulePath)) {
            $pdf->withBrowsershot(function (Browsershot $browsershot) use ($nodeModulePath): void {
                $browsershot
                    ->setNodeModulePath($nodeModulePath)
                    ->setEnvironmentOptions([
                        'NODE_PATH' => $nodeModulePath,
                    ]);
            });
        }

        return $pdf;
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
}
