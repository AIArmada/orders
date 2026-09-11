<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions\Concerns;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Docs\DataObjects\DocData;
use AIArmada\Docs\Enums\DocType;
use AIArmada\Docs\Models\Doc;
use AIArmada\Docs\States\DocStatus;
use AIArmada\Docs\States\Paid;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\View;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait BuildsOrderDocs
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
                    ])
                    ->timeout(30);
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
     * @param  array<string, mixed>  $metadata
     */
    protected function buildDocData(
        Order $order,
        DocType $docType,
        string $transactionId,
        string $gateway,
        array $metadata = [],
        bool $generatePdf = false,
    ): DocData {
        return new DocData(
            docType: $docType->value,
            docableType: $order->getMorphClass(),
            docableId: (string) $order->getKey(),
            status: DocStatus::fromString(Paid::class),
            issueDate: $order->paid_at ?? CarbonImmutable::now(),
            items: $this->buildItems($order),
            subtotalMinor: $order->subtotal,
            totalMinor: $order->grand_total,
            taxAmountMinor: $order->tax_total,
            discountAmountMinor: $order->discount_total,
            currency: $order->currency,
            notes: $order->notes,
            customerData: $this->buildCustomerData($order),
            metadata: array_filter(array_merge([
                'order_id' => $order->getKey(),
                'order_number' => $order->order_number,
                'payment_gateway' => $gateway,
                'payment_transaction_id' => $transactionId,
            ], $metadata), static fn (mixed $value): bool => $value !== null && $value !== ''),
            generatePdf: $generatePdf,
        );
    }

    protected function findExistingDoc(Order $order, DocType $docType): ?Doc
    {
        return Doc::query()
            ->withoutOwnerScope()
            ->where('docable_type', $order->getMorphClass())
            ->where('docable_id', $order->getKey())
            ->where('doc_type', $docType->value)
            ->first();
    }

    protected function runWithinOrderOwnerScope(Order $order, callable $callback): mixed
    {
        $owner = OwnerContext::fromTypeAndId($order->owner_type, $order->owner_id);

        return OwnerContext::withOwner($owner, $callback);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildItems(Order $order): array
    {
        $items = $order->items
            ->map(function (OrderItem $item): array {
                return array_filter([
                    'name' => $item->name,
                    'description' => $item->sku ? "SKU: {$item->sku}" : null,
                    'quantity' => $item->quantity,
                    'unit_price_minor' => $item->unit_price,
                ], static fn (mixed $value): bool => $value !== null && $value !== '');
            })
            ->values()
            ->all();

        if ($order->shipping_total > 0) {
            $items[] = [
                'name' => 'Shipping',
                'quantity' => 1,
                'unit_price_minor' => $order->shipping_total,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildCustomerData(Order $order): ?array
    {
        $address = $order->primaryAddress('billing') ?? $order->primaryAddress('shipping');

        if ($address === null) {
            return null;
        }

        $metadata = $address->metadata;
        $contact = is_array($metadata)
            && is_array($metadata[Order::ADDRESS_CONTACT_METADATA_KEY] ?? null)
            ? $metadata[Order::ADDRESS_CONTACT_METADATA_KEY]
            : [];
        $hasText = static fn (mixed $value): bool => is_string($value) && $value !== '';
        $name = mb_trim(implode(' ', array_filter([
            $contact['first_name'] ?? null,
            $contact['last_name'] ?? null,
        ], $hasText)));
        $locality = implode(', ', array_filter([
            $address->city,
            $address->state,
        ], $hasText));
        $location = mb_trim(implode(' ', array_filter([
            $locality,
            $address->postcode,
        ], $hasText)));

        return array_filter([
            'name' => $name !== '' ? $name : null,
            'email' => is_string($contact['email'] ?? null) ? $contact['email'] : null,
            'phone' => is_string($contact['phone'] ?? null) ? $contact['phone'] : null,
            'address' => implode("\n", array_filter([
                $name,
                $contact['company'] ?? null,
                $address->line1,
                $address->line2,
                $location,
                $address->country_code ?? $address->country,
            ], $hasText)),
            'city' => $address->city,
            'state' => $address->state,
            'postcode' => $address->postcode,
            'country_code' => $address->country_code ?? $address->country,
            'company' => is_string($contact['company'] ?? null) ? $contact['company'] : null,
        ], $hasText);
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
            'billing' => $order->primaryAddress('billing'),
            'shipping' => $order->primaryAddress('shipping'),
            'payments' => $order->payments()->where('status', 'completed')->get(),
        ], $data);
    }
}
