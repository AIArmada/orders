<?php

declare(strict_types=1);

namespace AIArmada\Orders\Services;

use AIArmada\Cart\Cart;
use AIArmada\Cart\Contracts\CartManagerInterface;
use AIArmada\Orders\Actions\Concerns\AssertsOrderOwnerBoundary;
use AIArmada\Orders\Actions\CreateOrder;
use AIArmada\Orders\Actions\CreateOrderFromCart;
use AIArmada\Orders\Actions\RegisterOrderPayment;
use AIArmada\Orders\Actions\RegisterOrderRefund;
use AIArmada\Orders\Contracts\OrderServiceInterface;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderItem;
use AIArmada\Orders\Models\OrderRefund;
use AIArmada\Orders\Transitions\DeliveryConfirmed;
use AIArmada\Orders\Transitions\OrderCanceled;
use AIArmada\Orders\Transitions\OrderCompleted;
use AIArmada\Orders\Transitions\ShipmentCreated;
use Illuminate\Database\Eloquent\Model;

/**
 * Stable facade for order lifecycle operations.
 *
 * Creation is owned by the CreateOrder actions; this service remains as the
 * stable interface used by existing integrations and Filament surfaces.
 */
final class OrderService implements OrderServiceInterface
{
    use AssertsOrderOwnerBoundary;

    public function __construct(
        private readonly CreateOrder $createOrder,
        private readonly CreateOrderFromCart $createOrderFromCart,
        private readonly RegisterOrderPayment $registerOrderPayment,
        private readonly RegisterOrderRefund $registerOrderRefund,
    ) {}

    public function createOrder(
        array $orderData,
        array $items,
        ?array $billingAddress = null,
        ?array $shippingAddress = null,
        ?string $intakeSource = null,
        ?string $intakeId = null,
    ): Order {
        return $this->createOrder->execute($orderData, $items, $billingAddress, $shippingAddress, $intakeSource, $intakeId);
    }

    public function createFromCart(
        Cart | CartManagerInterface $cart,
        Model $customer,
        ?array $billingAddress = null,
        ?array $shippingAddress = null,
        ?string $intakeSource = null,
        ?string $intakeId = null,
        ?string $sessionId = null,
    ): Order {
        return $this->createOrderFromCart->execute(
            $cart,
            $customer,
            $billingAddress,
            $shippingAddress,
            $intakeSource,
            $intakeId,
            $sessionId,
        );
    }

    public function addItem(Order $order, array $itemData): OrderItem
    {
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        return $this->createOrder->addItem($order, $itemData);
    }

    public function addAddress(Order $order, array $addressData, string $type): void
    {
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        $this->createOrder->addAddress($order, $addressData, $type);
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
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        return $this->registerOrderPayment->execute($order, $transactionId, $gateway, $amount, $metadata);
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

        return $this->registerOrderRefund->execute($order, $amount, $transactionId, $reason, $metadata);
    }

    public function createPendingRefund(
        Order $order,
        int $amount,
        string $transactionId,
        string $reason,
        array $metadata = [],
    ): OrderRefund {
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        return $this->registerOrderRefund->createPending($order, $amount, $transactionId, $reason, $metadata);
    }

    public function claimPendingRefundSubmission(OrderRefund $refund): bool
    {
        return $this->registerOrderRefund->claimPendingSubmission($refund);
    }

    public function completePendingRefund(OrderRefund $refund, ?string $transactionId = null): Order
    {
        return $this->registerOrderRefund->completePending($refund, $transactionId);
    }

    public function failPendingRefund(OrderRefund $refund, string $reason): OrderRefund
    {
        return $this->registerOrderRefund->failPending($refund, $reason);
    }

    public function recalculateTotals(Order $order): Order
    {
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        $order->recalculateTotals()->save();

        return $order->fresh();
    }
}
