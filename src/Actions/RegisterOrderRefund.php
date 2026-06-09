<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\Orders\Actions\Concerns\AssertsOrderOwnerBoundary;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Transitions\RefundProcessed;
use RuntimeException;

final class RegisterOrderRefund
{
    use AssertsOrderOwnerBoundary;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function execute(
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

        $transition = new RefundProcessed($order, $amount, $transactionId, $reason, $metadata);

        return $transition->handle();
    }
}
