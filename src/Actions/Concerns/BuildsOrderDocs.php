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

trait BuildsOrderDocs
{
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
            issueDate: $order->paid_at ?? now(),
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
        $address = $order->billingAddress ?? $order->shippingAddress;

        if ($address === null) {
            return null;
        }

        return array_filter([
            'name' => $address->getFullName(),
            'email' => $address->email,
            'phone' => $address->phone,
            'address' => $address->getFormatted(),
            'city' => $address->city,
            'state' => $address->state,
            'postcode' => $address->postcode,
            'country_code' => $address->country_code,
            'company' => $address->company,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

}
