<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Orders\Actions\Concerns\AssertsOrderOwnerBoundary;
use AIArmada\Orders\Events\OrderCreated;
use AIArmada\Orders\Exceptions\OrderIntakeConflictException;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderItem;
use AIArmada\Orders\States\Created;
use AIArmada\Orders\States\PendingPayment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class CreateOrder
{
    use AssertsOrderOwnerBoundary;

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

        $hasExplicitOrderNumber = isset($orderData['order_number'])
            && is_string($orderData['order_number'])
            && mb_trim($orderData['order_number']) !== '';

        if ($hasExplicitOrderNumber) {
            return $this->createInTransaction($orderData, $items, $billingAddress, $shippingAddress, $intakeSource, $intakeId);
        }

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return $this->createInTransaction(
                    [...$orderData, 'order_number' => Order::generateOrderNumber()],
                    $items,
                    $billingAddress,
                    $shippingAddress,
                    $intakeSource,
                    $intakeId,
                );
            } catch (QueryException $e) {
                if ($attempt === 3 || ! $this->isOrderNumberDuplicate($e)) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Order number generation exhausted its retry budget.');
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
        $matches = [
            (string) $existing->customer_id === (string) ($orderData['customer_id'] ?? ''),
            (string) $existing->customer_type === (string) ($orderData['customer_type'] ?? ''),
            $existing->subtotal === (int) ($orderData['subtotal'] ?? 0),
            $existing->discount_total === (int) ($orderData['discount_total'] ?? 0),
            $existing->shipping_total === (int) ($orderData['shipping_total'] ?? 0),
            $existing->tax_total === (int) ($orderData['tax_total'] ?? 0),
            $existing->grand_total === (int) ($orderData['grand_total'] ?? 0),
            mb_strtoupper((string) $existing->currency) === mb_strtoupper(
                (string) ($orderData['currency'] ?? config('orders.currency.default', 'MYR')),
            ),
        ];

        if (in_array(false, $matches, true)) {
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

    private function isOrderNumberDuplicate(QueryException $e): bool
    {
        if (! $this->isDuplicateKeyError($e)) {
            return false;
        }

        $message = mb_strtolower($e->getMessage());

        return str_contains($message, 'order_number')
            || str_contains($message, 'orders_order_number_unique');
    }

    /**
     * Normalize address fields through addressing when that optional package is installed.
     * Contact fields are deliberately retained because AddressData only owns postal fields.
     *
     * @param  array<string, mixed>  $addressData
     * @return array<string, mixed>
     */
    private function normalizeAddressData(array $addressData): array
    {
        $normalizerClass = 'AIArmada\\Addressing\\Actions\\NormalizeAddressDataAction';

        if (! class_exists($normalizerClass)) {
            return $addressData;
        }

        $normalizer = app($normalizerClass);
        $normalized = $normalizer->normalize($addressData);

        if (! method_exists($normalized, 'toModelAttributes')) {
            return $addressData;
        }

        /** @var array<string, mixed> $modelAttributes */
        $modelAttributes = $normalized->toModelAttributes();

        return array_merge($addressData, array_intersect_key($modelAttributes, array_flip([
            'country_id',
            'state_id',
            'city_id',
            'label',
            'line1',
            'line2',
            'line3',
            'city',
            'state',
            'postcode',
            'country',
            'country_code',
            'formatted_address',
            'latitude',
            'longitude',
            'components',
            'metadata',
            'google_maps_url',
            'waze_url',
            'navigation_links',
            'provider',
            'provider_place_id',
        ])));
    }

    /**
     * @param  array<string, mixed>  $itemData
     */
    public function addItem(Order $order, array $itemData): OrderItem
    {
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

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
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        $addressData = $this->normalizeAddressData($addressData);
        $firstName = $addressData['first_name'] ?? null;
        $lastName = $addressData['last_name'] ?? null;

        if ($firstName === null && isset($addressData['name'])) {
            $nameParts = explode(' ', mb_trim($addressData['name']), 2);
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';
        }

        $country = $addressData['country_code'] ?? $addressData['country'] ?? 'MY';

        if (! is_string($country) || preg_match('/^[A-Za-z]{2}$/', $country) !== 1) {
            throw new InvalidArgumentException('A two-letter country code is required for an order address.');
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
            'country_code' => mb_strtoupper($country),
            'phone' => $addressData['phone'] ?? null,
            'email' => $addressData['email'] ?? null,
            'metadata' => $addressData['metadata'] ?? null,
        ]);
    }

    private function findExistingIntake(string $intakeSource, string $intakeId): ?Order
    {
        return Order::query()
            ->forOwner(includeGlobal: (bool) config('orders.owner.include_global', false))
            ->where('intake_source', $intakeSource)
            ->where('intake_id', $intakeId)
            ->first();
    }

    private function assertOwnerBoundaryForCreation(): void
    {
        if (! (bool) config('orders.owner.enabled', false)) {
            return;
        }

        OwnerContext::assertResolvedOrExplicitGlobal(
            OwnerContext::resolve(),
            'Owner context is required for order creation when orders owner mode is enabled. Use OwnerContext::withOwner($owner, ...) or OwnerContext::withOwner(null, ...) for explicit global operations.',
        );
    }
}
