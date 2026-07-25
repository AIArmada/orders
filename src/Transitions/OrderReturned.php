<?php

declare(strict_types=1);

namespace AIArmada\Orders\Transitions;

use AIArmada\Orders\Events\OrderReturned as OrderReturnedEvent;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\States\Returned;
use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Transition;

/**
 * Transition to Returned state.
 *
 * Records returned_at once (historical fact, mirrors order_items.returned_at
 * at the order level). A subsequent refund transitions Returned → Refunded.
 */
final class OrderReturned extends Transition
{
    public function __construct(
        private Order $order,
        private ?string $reason = null,
        private ?string $returnedBy = null,
    ) {}

    public function handle(): Order
    {
        return DB::transaction(function (): Order {
            $this->order->status->transitionTo(Returned::class);
            $this->order->returned_at = now();
            $this->order->save();

            $this->order->orderNotes()->create([
                'user_id' => $this->returnedBy,
                'content' => 'Order returned' . ($this->reason !== null ? ": {$this->reason}" : ''),
                'visibility' => 'customer',
            ]);

            $order = $this->order;
            $reason = $this->reason;
            $returnedBy = $this->returnedBy;

            DB::afterCommit(function () use ($order, $reason, $returnedBy): void {
                event(new OrderReturnedEvent($order, $reason, $returnedBy));
            });

            return $this->order;
        });
    }
}
