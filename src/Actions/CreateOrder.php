<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Orders\Events\OrderCreated;
use AIArmada\Orders\Exceptions\OrderIntakeConflictException;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderItem;
use AIArmada\Orders\States\Created;
use AIArmada\Orders\States\PendingPayment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class CreateOrder
{
    /**
     * @param  array<string, mixed>  $orderData
     * @param  array<array<string, mixed>>  $items
     * @param  array<string, mixed>|null  $billingAddress
     * @param  array<string, mixed>|null  $shippingAddress
     * @param  string|null  $intakeSource  Deduplication identity source (e.g. 'checkout', 'api')
     * @param  string|null  $intakeId  Deduplication identity
     */
    public function execute(
        array $orderData,
        array $items,
        ?array $billingAddress = null,
        ?array $shippingAddress = null,
        ?string $intakeSource = null,
        ?string $intakeId = null,
    ): Order {
        $this->assertOwnerBoundaryForCreation();

        if ($intakeSource !== null && $intakeId !== null) {
            $existing = $this->findExistingIntake($intakeSource, $intakeId);

            if ($existing !== null) {
                $this->validateIntakeMatch($existing, $orderData);

                return $existing->fresh(['items', 'billingAddress', 'shippingAddress']);
            }
        }

        return $this->createInTransaction($orderData, $items, $billingAddress, $shippingAddress, $intakeSource, $intakeId);
    }

    /**
     * @param  array<string, mixed>  $orderData
     * @param  array<array<string, mixed>>  $items
     * @param  array<string, mixed>|null  $billingAddress
     * @param  array<string, mixed>|null  $shippingAddress
     */
    private function createInTransaction(
        array $orderData,
        array $items,
        ?array $billingAddress,
        ?array $shippingAddress,
        ?string $intakeSource,
        ?string $intakeId,
    ): Order {
        return DB::transaction(function () use ($orderData, $items, $billingAddress, $shippingAddress, $intakeSource, $intakeId): Order {
            try {
                $order = Order::create([
                    'order_number' => $orderData['order_number'] ?? Order::generateOrderNumber(),
                    'intake_source' => $intakeSource,
                    'intake_id' => $intakeId,
                    'status' => Created::class,
                    'customer_id' => $orderData['customer_id'] ?? null,
                    'customer_type' => $orderData['customer_type'] ?? null,
                    'subtotal' => $orderData['subtotal'] ?? 0,
                    'discount_total' => $orderData['discount_total'] ?? 0,
                    'shipping_total' => $orderData['shipping_total'] ?? 0,
                    'tax_total' => $orderData['tax_total'] ?? 0,
                    'grand_total' => $orderData['grand_total'] ?? 0,
                    'currency' => $orderData['currency'] ?? config('orders.currency.default', 'MYR'),
                    'notes' => $orderData['notes'] ?? null,
                    'metadata' => $orderData['metadata'] ?? null,
                ]);
            } catch (QueryException $e) {
                if ($intakeSource !== null && $intakeId !== null && $this->isDuplicateKeyError($e)) {
                    $existing = $this->findExistingIntake($intakeSource, $intakeId);

                    if ($existing !== null) {
                        $this->validateIntakeMatch($existing, $orderData);

                        return $existing->fresh(['items', 'billingAddress', 'shippingAddress']);
                    }
                }

                throw $e;
            }

            foreach ($items as $itemData) {
                $this->addItem($order, $itemData);
            }

            if ($billingAddress !== null) {
                $this->addAddress($order, $billingAddress, 'billing');
            }

            if ($shippingAddress !== null) {
                $this->addAddress($order, $shippingAddress, 'shipping');
            }

            $order->status->transitionTo(PendingPayment::class);

            DB::afterCommit(function () use ($order): void {
                event(new OrderCreated($order));
            });

            return $order->fresh(['items', 'billingAddress', 'shippingAddress']);
        });
    }

    /**
     * @param  array<string, mixed>  $orderData
     */
    private function validateIntakeMatch(Order $existing, array $orderData): void
    {
        if (
            (string) $existing->customer_id !== (string) ($orderData['customer_id'] ?? '')
            || (string) $existing->customer_type !== (string) ($orderData['customer_type'] ?? '')
        ) {
            throw OrderIntakeConflictException::duplicate(
                (string) $existing->intake_source,
                (string) $existing->intake_id,
                (string) $existing->getKey(),
            );
        }
    }

    private function isDuplicateKeyError(QueryException $e): bool
    {
        $sqlState = (string) $e->getPrevious()?->getCode();

        return $sqlState === '23000' || $sqlState === '23505';
    }

    /**
     * @param  array<string, mixed>  $itemData
     */
    public function addItem(Order $order, array $itemData): OrderItem
    {
        return $order->items()->create([
            'purchasable_id' => $itemData['purchasable_id'] ?? null,
            'purchasable_type' => $itemData['purchasable_type'] ?? null,
            'name' => $itemData['name'],
            'sku' => $itemData['sku'] ?? null,
            'quantity' => $itemData['quantity'] ?? 1,
            'unit_price' => $itemData['unit_price'] ?? 0,
            'discount_amount' => $itemData['discount_amount'] ?? 0,
            'tax_amount' => $itemData['tax_amount'] ?? 0,
            'currency' => $itemData['currency'] ?? $order->currency,
            'options' => $itemData['options'] ?? null,
            'metadata' => $itemData['metadata'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $addressData
     */
    public function addAddress(Order $order, array $addressData, string $type): void
    {
        $firstName = $addressData['first_name'] ?? null;
        $lastName = $addressData['last_name'] ?? null;

        if ($firstName === null && isset($addressData['name'])) {
            $nameParts = explode(' ', mb_trim($addressData['name']), 2);
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';
        }

        $country = $addressData['country_code'] ?? 'MY';
        if (mb_strlen($country) > 2) {
            $countryMap = [
                'malaysia' => 'MY',
                'singapore' => 'SG',
                'indonesia' => 'ID',
                'brunei' => 'BN',
                'thailand' => 'TH',
                'philippines' => 'PH',
            ];
            $country = $countryMap[mb_strtolower($country)] ?? 'MY';
        }

        $order->addresses()->create([
            'type' => $type,
            'first_name' => $firstName ?? '',
            'last_name' => $lastName ?? '',
            'company' => $addressData['company'] ?? null,
            'line1' => $addressData['line1'] ?? $addressData['address_line_1'] ?? $addressData['address'] ?? '',
            'line2' => $addressData['line2'] ?? $addressData['address_line_2'] ?? null,
            'city' => $addressData['city'] ?? '',
            'state' => $addressData['state'] ?? null,
            'postcode' => $addressData['postcode'] ?? $addressData['postal_code'] ?? '',
            'country_code' => $country,
            'phone' => $addressData['phone'] ?? null,
            'email' => $addressData['email'] ?? null,
            'metadata' => $addressData['metadata'] ?? null,
        ]);
    }

    private function findExistingIntake(string $intakeSource, string $intakeId): ?Order
    {
        return Order::query()
            ->where('intake_source', $intakeSource)
            ->where('intake_id', $intakeId)
            ->first();
    }

    private function assertOwnerBoundaryForCreation(): void
    {
        if (! (bool) config('orders.owner.enabled', true)) {
            return;
        }

        OwnerContext::assertResolvedOrExplicitGlobal(
            OwnerContext::resolve(),
            'Owner context is required for order creation when orders owner mode is enabled. Use OwnerContext::withOwner($owner, ...) or OwnerContext::withOwner(null, ...) for explicit global operations.',
        );
    }
}
