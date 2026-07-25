<?php

declare(strict_types=1);

namespace AIArmada\Orders\Transitions;

use AIArmada\Orders\Events\OrderHeld as OrderHeldEvent;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\States\OnHold;
use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Transition;

/**
 * Transition to OnHold state.
 *
 * Records held_at as a toggle timestamp: set here, cleared by
 * OrderHoldReleased. "Currently on hold" is therefore held_at !== null.
 */
final class OrderHeld extends Transition
{
    public function __construct(
        private Order $order,
        private string $reason,
        private ?string $heldBy = null,
    ) {}

    public function handle(): Order
    {
        return DB::transaction(function (): Order {
            $this->order->status->transitionTo(OnHold::class);
            $this->order->held_at = now();
            $this->order->save();

            $this->order->orderNotes()->create([
                'user_id' => $this->heldBy,
                'content' => "Order placed on hold: {$this->reason}",
                'visibility' => 'internal',
            ]);

            $order = $this->order;
            $reason = $this->reason;
            $heldBy = $this->heldBy;

            DB::afterCommit(function () use ($order, $reason, $heldBy): void {
                event(new OrderHeldEvent($order, $reason, $heldBy));
            });

            return $this->order;
        });
    }
}
