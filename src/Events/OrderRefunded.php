<?php

declare(strict_types=1);

namespace AIArmada\Orders\Events;

use AIArmada\Orders\Events\Concerns\HasOrderOwnerTuple;
use AIArmada\Orders\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class OrderRefunded
{
    use Dispatchable;
    use HasOrderOwnerTuple;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Order $order,
        public int $amount,
        public string $reason,
    ) {
        $this->hydrateOrderOwnerTuple($order);
    }
}
