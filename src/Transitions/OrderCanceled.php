<?php

declare(strict_types=1);

namespace AIArmada\Orders\Transitions;

use AIArmada\Orders\Actions\RegisterOrderRefund;
use AIArmada\Orders\Events\OrderCanceled as OrderCanceledEvent;
use AIArmada\Orders\Events\OrderCancelInitiated;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\States\Canceled;
use AIArmada\Orders\Support\OrderOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\ModelStates\Transition;

/**
 * Transition to Canceled state.
 *
 * This transition can happen from multiple states (Created, PendingPayment,
 * Processing, OnHold). It records the cancellation reason and optionally
 * releases inventory reservations and issues refunds.
 */
final class OrderCanceled extends Transition
{
    public function __construct(
        private Order $order,
        private string $reason,
        private ?string $canceledBy = null,
        private bool $issueRefund = true,
    ) {}

    public function handle(): Order
    {
        return DB::transaction(function (): Order {
            $this->order->newQuery()
                ->lockForUpdate()
                ->findOrFail($this->order->getKey());
            $this->order->refresh();
            $this->order->status->transitionTo(Canceled::class);
            $this->order->canceled_at = CarbonImmutable::now();
            $this->order->cancellation_reason = $this->reason;
            $this->order->held_at = null;
            $this->order->save();

            $this->order->orderNotes()->create([
                'user_id' => $this->canceledBy,
                'content' => "Order canceled: {$this->reason}",
                'visibility' => 'customer',
            ]);

            // Refunds are driven by money actually received, not the paid_at
            // flag: a partially paid order still needs its receipts returned,
            // and a never-paid order must not fall back to the order total.
            if ($this->issueRefund && $this->order->getTotalPaid() > 0) {
                app(RegisterOrderRefund::class)->createCancellationReservations($this->order, $this->reason);

                // The reservation accounting moved cached totals; refresh so
                // subscribers and later reads in this request see them.
                $this->order->refresh();
            }

            // Fulfillment staged but never relayed must not dispatch
            // after cancel cleanup ran: suppress it atomically with the
            // cancellation. Rows the relay already claimed are
            // re-validated against the order lifecycle under lock before
            // dispatch (see RelayOrderOutbox).
            OrderOutbox::suppressForOrder($this->order->getKey(), 'Order cancelled; fulfillment suppressed.');

            $order = $this->order;
            $reason = $this->reason;
            $canceledBy = $this->canceledBy;

            DB::afterCommit(function () use ($order, $reason, $canceledBy): void {
                event(new OrderCanceledEvent($order, $reason, $canceledBy));
                event(new OrderCancelInitiated($order, $reason, $canceledBy));
            });

            return $this->order;
        });
    }
}
