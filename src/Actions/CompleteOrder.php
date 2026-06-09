<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\Orders\Actions\Concerns\AssertsOrderOwnerBoundary;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Transitions\OrderCompleted;

final class CompleteOrder
{
    use AssertsOrderOwnerBoundary;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function execute(Order $order, array $metadata = []): Order
    {
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        $transition = new OrderCompleted($order, $metadata);

        return $transition->handle();
    }
}
