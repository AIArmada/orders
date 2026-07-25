<?php

declare(strict_types=1);

namespace AIArmada\Orders\Transitions;

use AIArmada\Orders\Events\OrderHoldReleased as OrderHoldReleasedEvent;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\States\Processing;
use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Transition;

/**
 * Release an order from OnHold back to Processing.
 *
 * Clears the held_at toggle so held_at !== null always means
 * "currently on hold".
 */
final class OrderHoldReleased extends Transition
{
    public function __construct(
        private Order $order,
        private ?string $reason = null,
        private ?string $releasedBy = null,
    ) {}

    public function handle(): Order
    {
        return DB::transaction(function (): Order {
            $this->order->status->transitionTo(Processing::class);
            $this->order->held_at = null;
            $this->order->save();

            $this->order->orderNotes()->create([
                'user_id' => $this->releasedBy,
                'content' => 'Order hold released' . ($this->reason !== null ? ": {$this->reason}" : ''),
                'visibility' => 'internal',
            ]);

            $order = $this->order;
            $reason = $this->reason;
            $releasedBy = $this->releasedBy;

            DB::afterCommit(function () use ($order, $reason, $releasedBy): void {
                event(new OrderHoldReleasedEvent($order, $reason, $releasedBy));
            });

            return $this->order;
        });
    }
}
