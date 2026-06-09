<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\Orders\Actions\Concerns\AssertsOrderOwnerBoundary;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Transitions\OrderCanceled;
use RuntimeException;

final class CancelOrder
{
    use AssertsOrderOwnerBoundary;

    public function execute(Order $order, string $reason, ?string $canceledBy = null): Order
    {
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        if (! $order->canBeCanceled()) {
            throw new RuntimeException("Order {$order->order_number} cannot be canceled in its current state.");
        }

        $transition = new OrderCanceled($order, $reason, $canceledBy);

        return $transition->handle();
    }
}
