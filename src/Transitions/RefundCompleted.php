<?php

declare(strict_types=1);

namespace AIArmada\Orders\Transitions;

use AIArmada\Orders\Enums\RefundStatus;
use AIArmada\Orders\Events\OrderRefunded;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderRefund;
use AIArmada\Orders\States\Refunded;
use AIArmada\Orders\Support\OrderOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Spatie\ModelStates\Transition;

/**
 * Complete a provider-confirmed pending refund.
 *
 * The refund row already carries its own per-payment allocation (created by
 * RegisterOrderRefund), so completion only settles that reservation; no
 * additional allocation or request-level metadata validation runs here.
 */
final class RefundCompleted extends Transition
{
    public function __construct(
        private OrderRefund $refund,
        private ?string $transactionId = null,
    ) {}

    public function handle(): Order
    {
        return DB::transaction(function (): Order {
            $refundId = $this->refund->getKey();
            $orderId = $this->refund->order_id;

            if ($orderId === null) {
                throw new RuntimeException('The refund must belong to an order.');
            }

            // Lock order → refund → payment everywhere, so completion cannot
            // interleave with allocation or failure in a way that leaves the
            // cached totals or payment terminal state inconsistent. The initial
            // read above identifies the immutable parent only; every decision
            // below is made from the locked refund row.
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->lockForUpdate()
                ->findOrFail($orderId);
            $lockedOrder->unsetRelation('refunds');

            /** @var OrderRefund|null $lockedRefund */
            $lockedRefund = $lockedOrder->refunds()
                ->whereKey($refundId)
                ->lockForUpdate()
                ->first();

            if (! $lockedRefund instanceof OrderRefund) {
                throw new RuntimeException('The refund must belong to an order.');
            }

            $lockedRefund->setRelation('order', $lockedOrder);

            // A completed replay preserves provider identity and terminal
            // timestamp and emits no event and no accounting change.
            if ($lockedRefund->isCompleted()) {
                return $lockedOrder;
            }

            if ($lockedRefund->isFailed()) {
                throw new RuntimeException('Only pending refunds can be completed.');
            }

            if (! $lockedRefund->isPending()) {
                throw new RuntimeException('Only pending refunds can be completed.');
            }

            $amount = (int) $lockedRefund->amount;

            if ($amount <= 0) {
                throw new InvalidArgumentException('Refund amount must be greater than zero.');
            }

            $lockedRefund->markAsCompleted($this->transactionId);
            $lockedOrder->unsetRelation('refunds');
            // The status-change event already moved the totals; refresh so
            // reads and the save below use fresh totals.
            $lockedOrder->refresh();

            // A payment reaches its own terminal state as soon as its whole
            // amount is returned, independent of the order-level remainder.
            $paidTotal = $lockedOrder->getTotalPaid();
            $refundedTotal = $lockedOrder->getTotalRefunded();

            foreach ($lockedOrder->payments()->get() as $payment) {
                $completedForPayment = (int) $lockedOrder->refunds()
                    ->where('payment_id', $payment->getKey())
                    ->where('status', RefundStatus::Completed->value)
                    ->sum('amount');

                if ($completedForPayment >= (int) $payment->amount) {
                    $payment->markAsRefunded();
                }
            }

            if ($paidTotal > 0 && $refundedTotal >= $paidTotal) {
                if (! ($lockedOrder->status instanceof Refunded)) {
                    $lockedOrder->status->transitionTo(Refunded::class);
                }

                $lockedOrder->refunded_at ??= CarbonImmutable::now();

                // A fully refunded order unwinds: fulfillment staged but
                // never relayed must not dispatch after the fact. Partial
                // refunds leave the status (and staged rows) untouched.
                OrderOutbox::suppressForOrder($lockedOrder->getKey(), 'Order fully refunded; fulfillment suppressed.');
            }

            $lockedOrder->save();

            event(new OrderRefunded(
                $lockedOrder,
                $amount,
                $lockedRefund->reason,
                (string) $lockedRefund->getKey(),
                $lockedRefund->metadata ?? [],
            ));

            return $lockedOrder;
        });
    }
}
