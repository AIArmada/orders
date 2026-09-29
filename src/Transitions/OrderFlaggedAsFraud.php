<?php

declare(strict_types=1);

namespace AIArmada\Orders\Transitions;

use AIArmada\Orders\Events\OrderFlaggedAsFraud as OrderFlaggedAsFraudEvent;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\States\Fraud;
use AIArmada\Orders\Support\OrderOutbox;
use Carbon\CarbonImmutable;
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
            // Locked like the sibling lifecycle transitions so flagging
            // serializes with relay/live dispatch: either this commit
            // (and its suppression below) wins and dispatch skips, or
            // dispatch commits first and the flag lands after — the same
            // order as the live path. Dispatch never runs for an order
            // that is already flagged.
            $this->order->newQuery()
                ->lockForUpdate()
                ->findOrFail($this->order->getKey());
            $this->order->refresh();
            $this->order->status->transitionTo(Fraud::class);
            $this->order->flagged_at = CarbonImmutable::now();
            $this->order->save();

            $this->order->orderNotes()->create([
                'user_id' => $this->flaggedBy,
                'content' => "Order flagged as fraud: {$this->reason}",
                'visibility' => 'internal',
            ]);

            // An order under investigation must not fulfill: suppress
            // unrelayed rows atomically with the flagging.
            OrderOutbox::suppressForOrder($this->order->getKey(), 'Order flagged as fraud; fulfillment suppressed.');

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
