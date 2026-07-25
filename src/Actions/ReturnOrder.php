<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\Orders\Actions\Concerns\AssertsOrderOwnerBoundary;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\States\Returned;
use AIArmada\Orders\Transitions\OrderReturned;
use RuntimeException;

final class ReturnOrder
{
    use AssertsOrderOwnerBoundary;

    public function execute(Order $order, ?string $reason = null, ?string $returnedBy = null): Order
    {
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        if (! $order->status->canTransitionTo(Returned::class)) {
            throw new RuntimeException("Order {$order->order_number} cannot be returned in its current state.");
        }

        $transition = new OrderReturned($order, $reason, $returnedBy);

        return $transition->handle();
    }
}
