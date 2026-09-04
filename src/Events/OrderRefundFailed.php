<?php

declare(strict_types=1);

namespace AIArmada\Orders\Events;

use AIArmada\Orders\Events\Concerns\HasOrderOwnerTuple;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderRefund;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class OrderRefundFailed
{
    use Dispatchable;
    use HasOrderOwnerTuple;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Order $order,
        public OrderRefund $refund,
        public string $reason,
        /** @var array<string, mixed> */
        public array $metadata = [],
    ) {
        $this->hydrateOrderOwnerTuple($order);
    }
}
