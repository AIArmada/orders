<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\Orders\Actions\Concerns\BuildsOrderPdf;
use AIArmada\Orders\Models\Order;
use Spatie\LaravelPdf\PdfBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Generate a buyer-facing receipt for an order.
 *
 * The order number is used as the stable receipt number so that repeated
 * downloads describe the same payment rather than producing new identifiers.
 */
final class GenerateReceipt
{
    use BuildsOrderPdf;

    /**
     * Generate and save a receipt to a path.
     */
    public function save(Order $order, string $path): string
    {
        $this->buildReceiptPdf($order)->save($path);

        return $path;
    }

    /**
     * Generate and download a receipt.
     */
    public function download(Order $order): PdfBuilder | StreamedResponse
    {
        if (! $this->hasPdfRuntime()) {
            return $this->downloadOrderHtmlFallback(
                order: $order,
                view: 'orders::pdf.invoice',
                filename: "receipt-{$order->order_number}.html",
                data: $this->documentData($order),
            );
        }

        return $this->buildReceiptPdf($order)->download();
    }

    private function buildReceiptPdf(Order $order): PdfBuilder
    {
        return $this->buildOrderPdf(
            order: $order,
            view: 'orders::pdf.invoice',
            filename: "receipt-{$order->order_number}.pdf",
            data: $this->documentData($order),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function documentData(Order $order): array
    {
        $receiptDate = $order->paid_at ?? $order->created_at ?? now();

        return [
            'documentTitle' => 'Receipt',
            'documentNumberLabel' => 'Receipt No:',
            'documentNumber' => $order->order_number,
            'documentDateLabel' => 'Receipt Date:',
            'documentDate' => $receiptDate,
            'documentFooterGreeting' => 'Thank you for your payment!',
            'documentFooterNote' => 'Keep this receipt for your records.',
        ];
    }
}
