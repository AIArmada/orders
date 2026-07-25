<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\Orders\Actions\Concerns\AssertsOrderOwnerBoundary;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\States\OnHold;
use AIArmada\Orders\Transitions\OrderHeld;
use RuntimeException;

final class HoldOrder
{
    use AssertsOrderOwnerBoundary;

    public function execute(Order $order, string $reason, ?string $heldBy = null): Order
    {
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        if (! $order->status->canTransitionTo(OnHold::class)) {
            throw new RuntimeException("Order {$order->order_number} cannot be placed on hold in its current state.");
        }

        $transition = new OrderHeld($order, $reason, $heldBy);

        return $transition->handle();
    }
}
