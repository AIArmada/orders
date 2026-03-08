<?php

declare(strict_types=1);

namespace AIArmada\Orders\Contracts;

use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for order service operations.
 */
interface OrderServiceInterface
{
    /**
     * Create a new order from cart data.
     *
     * @param  array<string, mixed>  $orderData
     * @param  array<array<string, mixed>>  $items
     * @param  array<string, mixed>|null  $billingAddress
     * @param  array<string, mixed>|null  $shippingAddress
     */
    public function createOrder(
        array $orderData,
        array $items,
        ?array $billingAddress = null,
        ?array $shippingAddress = null,
    ): Order;

    /**
     * Create order from a cart object.
     *
     * @param  array<string, mixed>|null  $billingAddress
     * @param  array<string, mixed>|null  $shippingAddress
     */
    public function createFromCart(
        object $cart,
        Model $customer,
        ?array $billingAddress = null,
        ?array $shippingAddress = null,
    ): Order;

    /**
     * Add an item to an order.
     *
     * @param  array<string, mixed>  $itemData
     */
    public function addItem(Order $order, array $itemData): OrderItem;

    /**
     * Add an address to an order.
     *
     * @param  array<string, mixed>  $addressData
     */
    public function addAddress(Order $order, array $addressData, string $type): void;

    /**
     * Cancel an order.
     */
    public function cancel(Order $order, string $reason, ?string $canceledBy = null): Order;

    /**
     * Confirm payment for an order.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function confirmPayment(
        Order $order,
        string $transactionId,
        string $gateway,
        int $amount,
        array $metadata = [],
    ): Order;

    /**
     * Mark order as shipped.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function ship(
        Order $order,
        string $carrier,
        string $trackingNumber,
        ?string $shipmentId = null,
        array $metadata = [],
    ): Order;

    /**
     * Confirm order delivery.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function confirmDelivery(Order $order, array $metadata = []): Order;

    /**
     * Process refund for returned order.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function processRefund(
        Order $order,
        int $amount,
        string $transactionId,
        string $reason,
        array $metadata = [],
    ): Order;

    /**
     * Recalculate order totals from items.
     */
    public function recalculateTotals(Order $order): Order;
}
