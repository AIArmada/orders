<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\Addressing\Actions\NormalizeAddressDataAction;
use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Support\ModelResolver;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Orders\Actions\Concerns\AssertsOrderOwnerBoundary;
use AIArmada\Orders\Events\OrderCreated;
use AIArmada\Orders\Exceptions\OrderIntakeConflictException;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderItem;
use AIArmada\Orders\States\Created;
use AIArmada\Orders\States\PendingPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
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
            // Serialize concurrent creates for the same intake identity. The
            // lookup-then-create below cannot rely on the unique key alone:
            // nullable owner columns are distinct on some drivers, so two
            // global rows with the same pair would not conflict.
            return Cache::lock($this->intakeLockKey($intakeSource, $intakeId), 10)->block(5, function () use (
                $orderData,
                $items,
                $billingAddress,
                $shippingAddress,
                $intakeSource,
                $intakeId,
            ): Order {
                $existing = $this->findExistingIntake($intakeSource, $intakeId);

                if ($existing !== null) {
                    $this->validateIntakeMatch($existing, $orderData);

                    return $existing->fresh(['items', 'addresses']);
                }

                return $this->createAfterValidation($orderData, $items, $billingAddress, $shippingAddress, $intakeSource, $intakeId);
            });
        }

        return $this->createAfterValidation($orderData, $items, $billingAddress, $shippingAddress, $intakeSource, $intakeId);
    }

    /**
     * @param  array<string, mixed>  $orderData
     * @param  array<array<string, mixed>>  $items
     * @param  array<string, mixed>|null  $billingAddress
     * @param  array<string, mixed>|null  $shippingAddress
     */
    private function createAfterValidation(
        array $orderData,
        array $items,
        ?array $billingAddress,
        ?array $shippingAddress,
        ?string $intakeSource,
        ?string $intakeId,
    ): Order {
        $this->validateTotalsInvariant($orderData);

        foreach ($items as $itemData) {
            $this->validateItemData($itemData);
        }

        $this->validateItemDiscountsFolded($orderData, $items);

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

    private function intakeLockKey(string $intakeSource, string $intakeId): string
    {
        $owner = OwnerContext::resolve();

        $scope = $owner instanceof Model
            ? $owner->getMorphClass() . ':' . $owner->getKey()
            : 'global';

        return 'orders-intake-' . sha1($scope . '|' . $intakeSource . '|' . $intakeId);
    }

    /**
     * Caller totals must be well-shaped: integer minor units, never negative,
     * and a zero grand total is only accepted when the discount covers the
     * subtotal, tax, and shipping (free orders). Positive grand totals are
     * engine-defined — the cart pipeline legitimately produces totals where
     * the grand is not the naive component sum — so no exact-sum check is
     * applied here; money movement stays bounded by the stored grand total
     * at payment time instead.
     *
     * @param  array<string, mixed>  $orderData
     */
    private function validateTotalsInvariant(array $orderData): void
    {
        $totals = [];

        foreach (['subtotal', 'discount_total', 'shipping_total', 'tax_total', 'grand_total'] as $key) {
            $value = $this->coerceOrderInt($orderData[$key] ?? 0, "Order total {$key}");

            if ($value < 0) {
                throw new InvalidArgumentException("Order total {$key} cannot be negative.");
            }

            $totals[$key] = $value;
        }

        if ($totals['grand_total'] === 0 && $totals['discount_total'] < $totals['subtotal'] + $totals['tax_total'] + $totals['shipping_total']) {
            throw new InvalidArgumentException('Order totals are inconsistent: a zero grand_total requires discount_total to cover subtotal, tax_total, and shipping_total.');
        }
    }

    /**
     * Item payloads are validated before any row is written so invalid input
     * fails fast without holding a transaction open.
     *
     * A blank name is accepted because cart and checkout lines may carry no
     * name; a missing or non-string name is rejected.
     *
     * @param  array<string, mixed>  $itemData
     */
    private function validateItemData(array $itemData): void
    {
        if (! isset($itemData['name']) || ! is_string($itemData['name'])) {
            throw new InvalidArgumentException('An order item name is required.');
        }

        if ($this->coerceOrderInt($itemData['quantity'] ?? 1, 'Order item quantity') < 1) {
            throw new InvalidArgumentException('Order item quantity must be at least 1.');
        }

        foreach (['unit_price', 'discount_amount', 'tax_amount'] as $key) {
            if ($this->coerceOrderInt($itemData[$key] ?? 0, "Order item {$key}") < 0) {
                throw new InvalidArgumentException("Order item {$key} cannot be negative.");
            }
        }
    }

    /**
     * Per-line discount_amount is an informational breakdown: the order-level
     * discount_total is authoritative (see Order::recalculateTotals), so the
     * caller must have folded line discounts into it. Reject payloads where
     * the breakdown exceeds the folded total instead of silently dropping
     * the difference from the grand total.
     *
     * @param  array<string, mixed>  $orderData
     * @param  array<array<string, mixed>>  $items
     */
    private function validateItemDiscountsFolded(array $orderData, array $items): void
    {
        $lineDiscounts = 0;

        foreach ($items as $itemData) {
            $lineDiscounts += $this->coerceOrderInt($itemData['discount_amount'] ?? 0, 'Order item discount_amount');
        }

        $discountTotal = $this->coerceOrderInt($orderData['discount_total'] ?? 0, 'Order total discount_total');

        if ($lineDiscounts > $discountTotal) {
            throw new InvalidArgumentException('Order totals are inconsistent: sum of item discount_amount exceeds discount_total; fold line discounts into discount_total.');
        }
    }

    private function coerceOrderInt(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', mb_trim($value)) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException("{$field} must be an integer.");
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
        // Per-row item creates stay inside the transaction on purpose: they carry
        // the owner-inherit guard, total calculation, and activity logging that
        // a bulk insert would bypass. Only the final re-read moves outside so
        // the write lock is not held for the follow-up selects.
        $orderKey = DB::transaction(function () use ($orderData, $items, $billingAddress, $shippingAddress, $intakeSource, $intakeId): string {
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

                        return (string) $existing->getKey();
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

            return (string) $order->getKey();
        });

        return Order::query()->with(['items', 'addresses'])->findOrFail($orderKey);
    }

    /**
     * @param  array<string, mixed>  $orderData
     */
    private function validateIntakeMatch(Order $existing, array $orderData): void
    {
        $matches = [
            mb_trim((string) $existing->customer_id) === mb_trim((string) ($orderData['customer_id'] ?? '')),
            mb_trim((string) $existing->customer_type) === mb_trim((string) ($orderData['customer_type'] ?? '')),
            $existing->subtotal === (int) ($orderData['subtotal'] ?? 0),
            $existing->discount_total === (int) ($orderData['discount_total'] ?? 0),
            $existing->shipping_total === (int) ($orderData['shipping_total'] ?? 0),
            $existing->tax_total === (int) ($orderData['tax_total'] ?? 0),
            $existing->grand_total === (int) ($orderData['grand_total'] ?? 0),
            mb_trim(mb_strtoupper((string) $existing->currency)) === mb_trim(mb_strtoupper(
                (string) ($orderData['currency'] ?? config('orders.currency.default', 'MYR')),
            )),
        ];

        if (in_array(false, $matches, true)) {
            throw OrderIntakeConflictException::duplicate(
                (string) $existing->intake_source,
                (string) $existing->intake_id,
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
     * Normalize address fields through the canonical addressing package.
     * Contact fields are retained in address metadata because Address only owns postal fields.
     *
     * @param  array<string, mixed>  $addressData
     * @return array<string, mixed>
     */
    private function normalizeAddressData(array $addressData): array
    {
        $normalized = app(NormalizeAddressDataAction::class)->normalize($addressData);

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
        $this->validateItemData($itemData);

        return $order->items()->create([
            'purchasable_id' => $itemData['purchasable_id'] ?? null,
            'purchasable_type' => $itemData['purchasable_type'] ?? null,
            'name' => $itemData['name'],
            'sku' => $itemData['sku'] ?? null,
            'quantity' => $this->coerceOrderInt($itemData['quantity'] ?? 1, 'Order item quantity'),
            'unit_price' => $this->coerceOrderInt($itemData['unit_price'] ?? 0, 'Order item unit_price'),
            'discount_amount' => $this->coerceOrderInt($itemData['discount_amount'] ?? 0, 'Order item discount_amount'),
            'tax_amount' => $this->coerceOrderInt($itemData['tax_amount'] ?? 0, 'Order item tax_amount'),
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

        $metadata = is_array($addressData['metadata'] ?? null) ? $addressData['metadata'] : [];
        $contactMetadata = $metadata[Order::ADDRESS_CONTACT_METADATA_KEY] ?? [];
        $metadata[Order::ADDRESS_CONTACT_METADATA_KEY] = array_merge(
            is_array($contactMetadata) ? $contactMetadata : [],
            [
                'first_name' => $firstName ?? '',
                'last_name' => $lastName ?? '',
                'company' => $addressData['company'] ?? null,
                'phone' => $addressData['phone'] ?? null,
                'email' => $addressData['email'] ?? null,
            ],
        );

        /** @var class-string<Address> $addressClass */
        $addressClass = ModelResolver::addressClass();
        $address = $addressClass::create([
            ...array_intersect_key($addressData, array_flip([
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
                'google_maps_url',
                'waze_url',
                'navigation_links',
                'provider',
                'provider_place_id',
            ])),
            'country_code' => mb_strtoupper($country),
            'metadata' => $metadata,
        ]);

        $order->attachAddress(
            address: $address,
            type: $type,
            isPrimary: true,
            label: is_string($addressData['label'] ?? null) ? $addressData['label'] : null,
        );
    }

    private function findExistingIntake(string $intakeSource, string $intakeId): ?Order
    {
        // Intake deduplication stays scope-local on purpose: an owned-context
        // retry must never match (and return) a global-scope row, and a
        // global-context retry only matches global rows. Cross-scope matching
        // would turn the idempotent retry into a cross-scope read oracle.
        return Order::query()
            ->forOwner(includeGlobal: false)
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
