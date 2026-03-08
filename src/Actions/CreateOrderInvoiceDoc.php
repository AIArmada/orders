<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Docs\Contracts\DocServiceInterface;
use AIArmada\Docs\DataObjects\DocData;
use AIArmada\Docs\Enums\DocType;
use AIArmada\Docs\Models\Doc;
use AIArmada\Docs\States\DocStatus;
use AIArmada\Docs\States\Paid;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderItem;

final class CreateOrderInvoiceDoc
{
    public function __construct(
        public DocServiceInterface $docService
    ) {}

    public function execute(Order $order, string $transactionId, string $gateway): ?Doc
    {
        $owner = OwnerContext::fromTypeAndId($order->owner_type, $order->owner_id);

        return OwnerContext::withOwner($owner, function () use ($order, $transactionId, $gateway): ?Doc {
            if ($this->invoiceExists($order)) {
                return null;
            }

            $docData = new DocData(
                docType: DocType::Invoice->value,
                docableType: $order->getMorphClass(),
                docableId: (string) $order->getKey(),
                status: DocStatus::fromString(Paid::class),
                issueDate: $order->paid_at ?? now(),
                items: $this->buildItems($order),
                subtotal: $this->toMajor($order->subtotal),
                total: $this->toMajor($order->grand_total),
                taxAmount: $this->toMajor($order->tax_total),
                discountAmount: $this->toMajor($order->discount_total),
                currency: $order->currency,
                notes: $order->notes,
                customerData: $this->buildCustomerData($order),
                metadata: [
                    'order_id' => $order->getKey(),
                    'order_number' => $order->order_number,
                    'payment_gateway' => $gateway,
                    'payment_transaction_id' => $transactionId,
                ],
                generatePdf: (bool) config('orders.integrations.docs.generate_pdf', false),
            );

            return $this->docService->create($docData);
        });
    }

    private function invoiceExists(Order $order): bool
    {
        return Doc::query()
            ->where('docable_type', $order->getMorphClass())
            ->where('docable_id', $order->getKey())
            ->where('doc_type', DocType::Invoice->value)
            ->exists();
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
                    'price' => $this->toMajor($item->unit_price),
                ], static fn ($value) => $value !== null && $value !== '');
            })
            ->values()
            ->all();

        if ($order->shipping_total > 0) {
            $items[] = [
                'name' => 'Shipping',
                'quantity' => 1,
                'price' => $this->toMajor($order->shipping_total),
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
            'country' => $address->country,
            'company' => $address->company,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function toMajor(int $amount): float
    {
        $decimals = (int) config('orders.currency.decimal_places', 2);
        $divisor = 10 ** max(0, $decimals);

        return $amount / $divisor;
    }
}
