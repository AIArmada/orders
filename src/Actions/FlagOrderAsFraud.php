<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\Orders\Actions\Concerns\AssertsOrderOwnerBoundary;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\States\Fraud;
use AIArmada\Orders\Transitions\OrderFlaggedAsFraud;
use RuntimeException;

final class FlagOrderAsFraud
{
    use AssertsOrderOwnerBoundary;

    public function execute(Order $order, string $reason, ?string $flaggedBy = null): Order
    {
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        if (! $order->status->canTransitionTo(Fraud::class)) {
            throw new RuntimeException("Order {$order->order_number} cannot be flagged as fraud in its current state.");
        }

        $transition = new OrderFlaggedAsFraud($order, $reason, $flaggedBy);

        return $transition->handle();
    }
}
