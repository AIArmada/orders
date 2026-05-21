<?php

declare(strict_types=1);

namespace AIArmada\Orders\Events;

use AIArmada\Orders\Events\Concerns\HasOrderOwnerTuple;
use AIArmada\Orders\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event dispatched when inventory deduction is required after payment.
 *
 * The inventory package should listen for this event and handle the deduction.
 */
final class InventoryDeductionRequired
{
    use Dispatchable;
    use HasOrderOwnerTuple;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Order $order,
    ) {
        $this->hydrateOrderOwnerTuple($order);
    }
}
