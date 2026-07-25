<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\Orders\Actions\Concerns\AssertsOrderOwnerBoundary;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\States\OnHold;
use AIArmada\Orders\Transitions\OrderHoldReleased;
use RuntimeException;

final class ReleaseOrderHold
{
    use AssertsOrderOwnerBoundary;

    public function execute(Order $order, ?string $reason = null, ?string $releasedBy = null): Order
    {
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        if (! $order->status instanceof OnHold) {
            throw new RuntimeException("Order {$order->order_number} is not on hold.");
        }

        $transition = new OrderHoldReleased($order, $reason, $releasedBy);

        return $transition->handle();
    }
}
