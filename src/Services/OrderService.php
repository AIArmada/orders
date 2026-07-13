<?php

declare(strict_types=1);

namespace AIArmada\Orders\Services;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Orders\Actions\CreateOrder;
use AIArmada\Orders\Actions\CreateOrderFromCart;
use AIArmada\Orders\Actions\RegisterOrderPayment;
use AIArmada\Orders\Contracts\OrderServiceInterface;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderItem;
use AIArmada\Orders\Transitions\DeliveryConfirmed;
use AIArmada\Orders\Transitions\OrderCanceled;
use AIArmada\Orders\Transitions\OrderCompleted;
use AIArmada\Orders\Transitions\RefundProcessed;
use AIArmada\Orders\Transitions\ShipmentCreated;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Compatibility facade for order lifecycle operations.
 *
 * Creation is owned by the CreateOrder actions; this service remains as the
 * stable interface used by existing integrations and Filament surfaces.
 */
final class OrderService implements OrderServiceInterface
{
    public function createOrder(
        array $orderData,
        array $items,
        ?array $billingAddress = null,
        ?array $shippingAddress = null,
        ?string $intakeSource = null,
        ?string $intakeId = null,
    ): Order {
        return (new CreateOrder)->execute($orderData, $items, $billingAddress, $shippingAddress, $intakeSource, $intakeId);
    }

    public function createFromCart(
        object $cart,
        Model $customer,
        ?array $billingAddress = null,
        ?array $shippingAddress = null,
        ?string $intakeSource = null,
        ?string $intakeId = null,
    ): Order {
        return (new CreateOrderFromCart(new CreateOrder))->execute(
            $cart,
            $customer,
            $billingAddress,
            $shippingAddress,
            $intakeSource,
            $intakeId,
        );
    }

    public function addItem(Order $order, array $itemData): OrderItem
    {
        return (new CreateOrder)->addItem($order, $itemData);
    }

    public function addAddress(Order $order, array $addressData, string $type): void
    {
        (new CreateOrder)->addAddress($order, $addressData, $type);
    }

    public function cancel(Order $order, string $reason, ?string $canceledBy = null): Order
    {
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        return (new OrderCanceled($order, $reason, $canceledBy))->handle();
    }

    public function confirmPayment(
        Order $order,
        string $transactionId,
        string $gateway,
        int $amount,
        array $metadata = [],
    ): Order {
        return (new RegisterOrderPayment)->execute($order, $transactionId, $gateway, $amount, $metadata);
    }

    public function ship(
        Order $order,
        string $carrier,
        string $trackingNumber,
        ?string $shipmentId = null,
        array $metadata = [],
    ): Order {
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        return (new ShipmentCreated($order, $carrier, $trackingNumber, $shipmentId, $metadata))->handle();
    }

    public function confirmDelivery(Order $order, array $metadata = []): Order
    {
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        return (new DeliveryConfirmed($order, $metadata))->handle();
    }

    public function complete(Order $order, array $metadata = []): Order
    {
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        return (new OrderCompleted($order, $metadata))->handle();
    }

    public function processRefund(
        Order $order,
        int $amount,
        string $transactionId,
        string $reason,
        array $metadata = [],
    ): Order {
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        if (! $order->canBeRefunded()) {
            throw new RuntimeException("Order {$order->order_number} cannot be refunded in its current state.");
        }

        return (new RefundProcessed($order, $amount, $transactionId, $reason, $metadata))->handle();
    }

    public function recalculateTotals(Order $order): Order
    {
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        $order->recalculateTotals()->save();

        return $order->fresh();
    }

    private function assertOwnerBoundaryForMutation(Order $order, string $operation): void
    {
        if (! (bool) config('orders.owner.enabled', true)) {
            return;
        }

        $owner = OwnerContext::resolve();

        if ($order->hasOwner()) {
            if ($owner === null) {
                throw new RuntimeException(sprintf(
                    'A matching owner context is required for %s when mutating owned orders.',
                    $operation,
                ));
            }

            if (! $order->belongsToOwner($owner)) {
                throw new RuntimeException(sprintf(
                    'Cross-owner mutation blocked for %s. The current owner context does not match the order owner.',
                    $operation,
                ));
            }

            return;
        }

        OwnerContext::assertResolvedOrExplicitGlobal(
            $owner,
            sprintf('Explicit global owner context is required for %s.', $operation),
        );

        if (! OwnerContext::isExplicitGlobal()) {
            throw new RuntimeException(sprintf('Explicit global owner context is required for %s.', $operation));
        }
    }
}
