<?php

declare(strict_types=1);

namespace AIArmada\Orders\Transitions;

use AIArmada\Orders\Events\OrderFlaggedAsFraud as OrderFlaggedAsFraudEvent;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\States\Fraud;
use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Transition;

/**
 * Transition to Fraud state (terminal).
 *
 * Records flagged_at once; Fraud is final so the timestamp is never cleared.
 */
final class OrderFlaggedAsFraud extends Transition
{
    public function __construct(
        private Order $order,
        private string $reason,
        private ?string $flaggedBy = null,
    ) {}

    public function handle(): Order
    {
        return DB::transaction(function (): Order {
            $this->order->status->transitionTo(Fraud::class);
            $this->order->flagged_at = now();
            $this->order->save();

            $this->order->orderNotes()->create([
                'user_id' => $this->flaggedBy,
                'content' => "Order flagged as fraud: {$this->reason}",
                'visibility' => 'internal',
            ]);

            $order = $this->order;
            $reason = $this->reason;
            $flaggedBy = $this->flaggedBy;

            DB::afterCommit(function () use ($order, $reason, $flaggedBy): void {
                event(new OrderFlaggedAsFraudEvent($order, $reason, $flaggedBy));
            });

            return $this->order;
        });
    }
}
