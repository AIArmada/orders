<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions\Concerns;

use AIArmada\Orders\Models\Order;
use Illuminate\Support\Facades\View;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait BuildsOrderPdf
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function buildOrderPdf(
        Order $order,
        string $view,
        string $filename,
        array $data,
    ): PdfBuilder {
        $pdf = Pdf::view($view, $this->orderViewData($order, $data))
            ->format('a4')
            ->margins(15, 15, 15, 15)
            ->name($filename);

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
     * @param  array<string, mixed>  $data
     */
    protected function downloadOrderHtmlFallback(
        Order $order,
        string $view,
        string $filename,
        array $data,
    ): StreamedResponse {
        return response()->streamDownload(function () use ($order, $view, $data): void {
            echo View::make($view, $this->orderViewData($order, $data))->render();
        }, $filename, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    protected function hasPdfRuntime(): bool
    {
        return is_file(base_path('node_modules/puppeteer/package.json'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function orderViewData(Order $order, array $data): array
    {
        return array_merge([
            'order' => $order,
            'items' => $order->items,
            'billingAddress' => $order->billingAddress,
            'shippingAddress' => $order->shippingAddress,
            'payments' => $order->payments()->where('status', 'completed')->get(),
        ], $data);
    }
}
