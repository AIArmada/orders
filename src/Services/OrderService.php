<?php

declare(strict_types=1);

namespace AIArmada\Orders\Services;

use AIArmada\Orders\Contracts\OrderServiceInterface;
use AIArmada\Orders\Events\OrderCreated;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderItem;
use AIArmada\Orders\States\Created;
use AIArmada\Orders\States\PendingPayment;
use AIArmada\Orders\Transitions\DeliveryConfirmed;
use AIArmada\Orders\Transitions\OrderCanceled;
use AIArmada\Orders\Transitions\PaymentConfirmed;
use AIArmada\Orders\Transitions\RefundProcessed;
use AIArmada\Orders\Transitions\ShipmentCreated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Order service for order lifecycle management.
 */
final class OrderService implements OrderServiceInterface
{
    /**
     * Create a new order from cart data.
     *
     * @param  array<string, mixed>  $orderData  Order header data
     * @param  array<array<string, mixed>>  $items  Array of item data
     * @param  array<string, mixed>|null  $billingAddress  Billing address data
     * @param  array<string, mixed>|null  $shippingAddress  Shipping address data
     */
    public function createOrder(
        array $orderData,
        array $items,
        ?array $billingAddress = null,
        ?array $shippingAddress = null,
    ): Order {
        return DB::transaction(function () use ($orderData, $items, $billingAddress, $shippingAddress): Order {
            // Create the order
            $order = Order::create([
                'order_number' => $orderData['order_number'] ?? Order::generateOrderNumber(),
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

            // Add order items
            foreach ($items as $itemData) {
                $this->addItem($order, $itemData);
            }

            // Add billing address
            if ($billingAddress !== null) {
                $this->addAddress($order, $billingAddress, 'billing');
            }

            // Add shipping address
            if ($shippingAddress !== null) {
                $this->addAddress($order, $shippingAddress, 'shipping');
            }

            // Transition to pending payment
            $order->status->transitionTo(PendingPayment::class);

            // Dispatch event
            event(new OrderCreated($order));

            return $order->fresh(['items', 'billingAddress', 'shippingAddress']);
        });
    }

    /**
     * Create order from a cart object (if cart package is available).
     *
     * @param  object  $cart  Cart object with items and totals
     * @param  Model  $customer  Customer model
     * @param  array<string, mixed>|null  $billingAddress
     * @param  array<string, mixed>|null  $shippingAddress
     */
    public function createFromCart(
        object $cart,
        Model $customer,
        ?array $billingAddress = null,
        ?array $shippingAddress = null,
    ): Order {
        // Extract cart data
        $orderData = [
            'customer_id' => $customer->getKey(),
            'customer_type' => $customer->getMorphClass(),
            'subtotal' => $cart->subtotal ?? 0,
            'discount_total' => $cart->discount ?? 0,
            'shipping_total' => $cart->shipping ?? 0,
            'tax_total' => $cart->tax ?? 0,
            'grand_total' => $cart->total ?? 0,
            'currency' => $cart->currency ?? config('orders.currency.default', 'MYR'),
            'metadata' => [
                'cart_id' => $cart->id ?? null,
                'session_id' => session()->getId(),
            ],
        ];

        // Convert cart items to order item format
        $items = [];
        foreach ($cart->items ?? [] as $cartItem) {
            $items[] = [
                'purchasable_id' => $cartItem->purchasable_id ?? null,
                'purchasable_type' => $cartItem->purchasable_type ?? null,
                'name' => $cartItem->name ?? 'Unknown Item',
                'sku' => $cartItem->sku ?? null,
                'quantity' => $cartItem->quantity ?? 1,
                'unit_price' => $cartItem->price ?? 0,
                'discount_amount' => $cartItem->discount ?? 0,
                'tax_amount' => $cartItem->tax ?? 0,
                'options' => $cartItem->options ?? null,
                'metadata' => $cartItem->metadata ?? null,
            ];
        }

        return $this->createOrder($orderData, $items, $billingAddress, $shippingAddress);
    }

    /**
     * Add an item to an order.
     *
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
     * Add an address to an order.
     *
     * @param  array<string, mixed>  $addressData
     */
    public function addAddress(Order $order, array $addressData, string $type): void
    {
        // Handle 'name' field by splitting into first_name/last_name if not provided separately
        $firstName = $addressData['first_name'] ?? null;
        $lastName = $addressData['last_name'] ?? null;

        if ($firstName === null && isset($addressData['name'])) {
            $nameParts = explode(' ', mb_trim($addressData['name']), 2);
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';
        }

        // Get country code - convert full names to ISO 2-letter codes
        $country = $addressData['country_code'] ?? $addressData['country'] ?? 'MY';
        if (mb_strlen($country) > 2) {
            // Map common country names to ISO codes
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
            'country' => $country,
            'phone' => $addressData['phone'] ?? null,
            'email' => $addressData['email'] ?? null,
            'metadata' => $addressData['metadata'] ?? null,
        ]);
    }

    /**
     * Cancel an order.
     */
    public function cancel(Order $order, string $reason, ?string $canceledBy = null): Order
    {
        if (! $order->canBeCanceled()) {
            throw new RuntimeException("Order {$order->order_number} cannot be canceled in its current state.");
        }

        // Use transition class for proper state change with side effects
        $transition = new OrderCanceled($order, $reason, $canceledBy);

        return $transition->handle();
    }

    /**
     * Confirm payment for an order.
     */
    public function confirmPayment(
        Order $order,
        string $transactionId,
        string $gateway,
        int $amount,
        array $metadata = [],
    ): Order {
        $transition = new PaymentConfirmed(
            $order,
            $transactionId,
            $gateway,
            $amount,
            $metadata,
        );

        return $transition->handle();
    }

    /**
     * Mark order as shipped.
     */
    public function ship(
        Order $order,
        string $carrier,
        string $trackingNumber,
        ?string $shipmentId = null,
        array $metadata = [],
    ): Order {
        $transition = new ShipmentCreated(
            $order,
            $carrier,
            $trackingNumber,
            $shipmentId,
            $metadata,
        );

        return $transition->handle();
    }

    /**
     * Confirm order delivery.
     */
    public function confirmDelivery(Order $order, array $metadata = []): Order
    {
        $transition = new DeliveryConfirmed($order, $metadata);

        return $transition->handle();
    }

    /**
     * Process refund for returned order.
     */
    public function processRefund(
        Order $order,
        int $amount,
        string $transactionId,
        string $reason,
        array $metadata = [],
    ): Order {
        if (! $order->canBeRefunded()) {
            throw new RuntimeException("Order {$order->order_number} cannot be refunded in its current state.");
        }

        $transition = new RefundProcessed(
            $order,
            $amount,
            $transactionId,
            $reason,
            $metadata,
        );

        return $transition->handle();
    }

    /**
     * Recalculate order totals from items.
     */
    public function recalculateTotals(Order $order): Order
    {
        $order->recalculateTotals()->save();

        return $order->fresh();
    }
}
