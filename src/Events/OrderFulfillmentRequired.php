<?php

declare(strict_types=1);

namespace AIArmada\Orders\Events;

use AIArmada\Orders\Events\Concerns\HasOrderOwnerTuple;
use AIArmada\Orders\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An order is confirmed and ready for fulfillment: passes, event
 * registrations, promotion usage, and commission attribution.
 *
 * Emitted by both the paid path (PaymentConfirmed) and the free path
 * (FreeOrderConfirmed). Unlike OrderPaid, it carries no payment claim —
 * invoice creation and payment confirmation emails stay on OrderPaid.
 */
final class OrderFulfillmentRequired
{
    use Dispatchable;
    use HasOrderOwnerTuple;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Order $order,
        public string $transactionId,
        public string $gateway,
    ) {
        $this->hydrateOrderOwnerTuple($order);
    }
}
