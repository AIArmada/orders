<?php

declare(strict_types=1);

namespace AIArmada\Orders\Transitions;

use AIArmada\CommerceSupport\Support\MoneyFormatter;
use AIArmada\Orders\Enums\RefundStatus;
use AIArmada\Orders\Events\OrderRefunded;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderPayment;
use AIArmada\Orders\Models\OrderRefund;
use AIArmada\Orders\States\Refunded;
use AIArmada\Orders\Support\OrderOutbox;
use AIArmada\Orders\Support\RefundAllocationValidator;
use AIArmada\Orders\Support\RefundAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\ModelStates\Transition;

/**
 * Transition from Returned → Refunded.
 *
 * This transition is triggered when a refund is processed for returned items.
 * The amount is allocated FIFO across the payments that actually received
 * money, bounded by each payment's own remaining amount; when the caller
 * specifies a payment, the whole amount lands on that payment instead.
 */
final class RefundProcessed extends Transition
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        private Order $order,
        private int $amount,
        private string $transactionId,
        private string $reason,
        private array $metadata = [],
        private ?OrderPayment $payment = null,
    ) {}

    public function handle(): Order
    {
        return DB::transaction(function (): Order {
            $now = CarbonImmutable::now();

            if ($this->amount <= 0) {
                throw new InvalidArgumentException('Refund amount must be greater than zero.');
            }

            RefundAllocationValidator::assertAmount($this->metadata, $this->amount);

            $this->order->newQuery()
                ->lockForUpdate()
                ->findOrFail($this->order->getKey());
            $this->order->refresh();

            $allocations = RefundAllocator::allocate($this->order, $this->amount, $this->payment);

            /** @var list<OrderRefund> $created */
            $created = [];

            foreach ($allocations as $allocation) {
                $payment = $allocation['payment'];

                // Record refund. The lifecycle timestamp is assigned directly
                // because refund lifecycle fields are not mass assignable. The
                // allocation key carries the caller's request identity while
                // the transaction id is the provider refund identity.
                $refund = $this->order->refunds()->make([
                    'payment_id' => $payment->getKey(),
                    'gateway' => $payment->gateway,
                    'transaction_id' => $this->transactionId,
                    'allocation_key' => $this->transactionId,
                    'amount' => $allocation['amount'],
                    'currency' => $payment->currency !== '' ? MoneyFormatter::normalizeCurrency($payment->currency) : MoneyFormatter::normalizeCurrency($this->order->currency),
                    'status' => RefundStatus::Completed,
                    'reason' => $this->reason,
                    'metadata' => $this->metadata,
                ]);
                $refund->refunded_at = $now;
                $refund->save();
                $this->order->unsetRelation('refunds');
                // The created-refund event already synced refunded_total.
                $this->order->refresh();

                $created[] = $refund;
            }

            // A payment reaches its own terminal state as soon as its whole
            // amount is returned, independent of the order-level remainder.
            $paidTotal = $this->order->getTotalPaid();
            $refundedTotal = $this->order->getTotalRefunded();

            foreach ($this->order->payments()->get() as $payment) {
                $attributed = (int) $this->order->refunds()
                    ->where('payment_id', $payment->getKey())
                    ->where('status', RefundStatus::Completed->value)
                    ->sum('amount');

                if ($attributed >= (int) $payment->amount) {
                    $payment->markAsRefunded();
                }
            }

            // The order is terminal only when the whole received amount has
            // been returned; a never-paid order never reaches that ceiling.
            if ($paidTotal > 0 && $refundedTotal >= $paidTotal) {
                $this->order->refunded_at = $now;
                $this->order->status->transitionTo(Refunded::class);

                // Same terminal marking as cancel and completed refunds:
                // unrelayed fulfillment must not dispatch after a full
                // refund. Partial refunds leave staged rows untouched.
                OrderOutbox::suppressForOrder($this->order->getKey(), 'Order fully refunded; fulfillment suppressed.');
            }

            $this->order->save();

            // One event per persisted allocation row: the recorded identity
            // and amount are per-payment facts, not the aggregate request.
            foreach ($created as $refund) {
                event(new OrderRefunded(
                    $this->order,
                    (int) $refund->amount,
                    $this->reason,
                    (string) $refund->getKey(),
                    $this->metadata,
                ));
            }

            return $this->order;
        });
    }
}
