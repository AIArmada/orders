<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\CommerceSupport\Support\MoneyFormatter;
use AIArmada\Orders\Actions\Concerns\AssertsOrderOwnerBoundary;
use AIArmada\Orders\Enums\RefundStatus;
use AIArmada\Orders\Events\OrderRefundFailed;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderPayment;
use AIArmada\Orders\Models\OrderRefund;
use AIArmada\Orders\States\Canceled;
use AIArmada\Orders\Support\RefundAllocationValidator;
use AIArmada\Orders\Support\RefundAllocator;
use AIArmada\Orders\Transitions\RefundCompleted;
use AIArmada\Orders\Transitions\RefundProcessed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class RegisterOrderRefund
{
    use AssertsOrderOwnerBoundary;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function execute(
        Order $order,
        int $amount,
        string $transactionId,
        string $reason,
        array $metadata = [],
        ?OrderPayment $payment = null,
    ): Order {
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        if (mb_trim($transactionId) === '') {
            throw new InvalidArgumentException('A refund transaction ID is required.');
        }

        return DB::transaction(function () use ($order, $amount, $transactionId, $reason, $metadata, $payment): Order {
            // Keep the caller's model instance while locking the database row,
            // so retries cannot create duplicate refunds or overshoot the
            // remaining refundable amount.
            $order->newQuery()
                ->lockForUpdate()
                ->findOrFail($order->getKey());
            $order->refresh();

            $existingRefund = $order->refunds()
                ->where('transaction_id', $transactionId)
                ->first();

            if ($existingRefund instanceof OrderRefund) {
                if ($existingRefund->isPending()) {
                    throw new RuntimeException("Refund transaction {$transactionId} is already pending.");
                }

                if ($existingRefund->isFailed()) {
                    throw new RuntimeException("Refund transaction {$transactionId} has already failed.");
                }

                return $order;
            }

            if (! $order->canBeRefunded()) {
                throw new RuntimeException("Order {$order->order_number} cannot be refunded in its current state.");
            }

            return (new RefundProcessed($order, $amount, $transactionId, $reason, $metadata, $payment))->handle();
        });
    }

    /**
     * Create the pending reservations for a refund request across the
     * payments that received money.
     *
     * The request identity (transactionId) is the idempotency key: a retry
     * with the same key returns the already-reserved allocations and a failed
     * request stays failed. When a payment is specified, the whole amount is
     * reserved against it and must fit that payment's own remaining amount;
     * otherwise the amount is allocated FIFO across the eligible payments.
     *
     * @param  array<string, mixed>  $metadata
     * @return list<OrderRefund>
     */
    public function createPending(
        Order $order,
        int $amount,
        string $transactionId,
        string $reason,
        array $metadata = [],
        ?OrderPayment $payment = null,
    ): array {
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        if ($amount <= 0) {
            throw new InvalidArgumentException('Refund amount must be greater than zero.');
        }

        if (mb_trim($transactionId) === '') {
            throw new InvalidArgumentException('A refund transaction ID is required.');
        }

        RefundAllocationValidator::assertAmount($metadata, $amount);

        return DB::transaction(function () use ($order, $amount, $transactionId, $reason, $metadata, $payment): array {
            $lockedOrder = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());

            // The allocation key is the caller's idempotency key. Check it
            // after locking the order so concurrent retries cannot create two
            // reservation sets, even when the first refund makes the order
            // unrefundable before the retry arrives.
            $existing = $lockedOrder->refunds()
                ->where('allocation_key', $transactionId)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            if ($existing->isNotEmpty()) {
                /** @var OrderRefund $existingRefund */
                $existingRefund = $existing->first();

                if ($existingRefund->isFailed()) {
                    throw new RuntimeException("Refund transaction {$transactionId} has already failed.");
                }

                if ($payment !== null && $existing->count() !== 1) {
                    throw new InvalidArgumentException('The specified payment does not match the reserved refund allocations.');
                }

                if ($payment !== null && (string) $existingRefund->payment_id !== (string) $payment->getKey()) {
                    throw new InvalidArgumentException('The specified payment does not match the reserved refund allocations.');
                }

                return array_values($existing->all());
            }

            if (! $lockedOrder->canBeRefunded()) {
                throw new RuntimeException("Order {$lockedOrder->order_number} cannot be refunded in its current state.");
            }

            $allocations = RefundAllocator::allocate($lockedOrder, $amount, $payment);

            $refunds = [];

            foreach ($allocations as $allocation) {
                $refunds[] = $this->reservePendingRefund(
                    order: $lockedOrder,
                    payment: $allocation['payment'],
                    amount: $allocation['amount'],
                    allocationKey: $transactionId,
                    reason: $reason,
                    metadata: $metadata,
                );
            }

            return $refunds;
        });
    }

    /**
     * Reserve the remaining refundable balance as pending refunds when an
     * order is canceled.
     *
     * The remainder is total paid minus settled refunds minus existing
     * pending reservations, allocated FIFO across the actual completed
     * payments so no single payment is reserved beyond its own remaining
     * balance. Reservation identities are stable per payment, so retries
     * cannot reserve twice, and a never-paid order never falls back to the
     * order total as refundable cash.
     *
     * @return list<OrderRefund>
     */
    public function createCancellationReservations(Order $order, string $reason): array
    {
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        return DB::transaction(function () use ($order, $reason): array {
            $lockedOrder = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());

            if (! $lockedOrder->status instanceof Canceled) {
                throw new RuntimeException(sprintf(
                    'Order %s must be canceled before cancellation refunds can be reserved.',
                    $lockedOrder->order_number,
                ));
            }

            // Only money actually received is refundable: the shared
            // allocator never falls back to the order total, so an order that
            // was never paid reserves nothing.
            $remaining = RefundAllocator::remainingForOrder($lockedOrder);

            if ($remaining <= 0) {
                return [];
            }

            $reservations = [];

            foreach (RefundAllocator::eligiblePayments($lockedOrder) as $payment) {
                if ($remaining <= 0) {
                    break;
                }

                // Stable per-payment identity: retries (including after a
                // recorded failure) never reserve the same payment twice.
                $allocationKey = sprintf('cancel:%s:%s', $lockedOrder->getKey(), $payment->getKey());

                if ($lockedOrder->refunds()->where('allocation_key', $allocationKey)->exists()) {
                    continue;
                }

                $amount = min(RefundAllocator::remainingForPayment($payment), $remaining);

                if ($amount <= 0) {
                    continue;
                }

                $reservations[] = $this->reservePendingRefund(
                    order: $lockedOrder,
                    payment: $payment,
                    amount: $amount,
                    allocationKey: $allocationKey,
                    reason: 'Order canceled: ' . $reason,
                    metadata: [],
                );

                $remaining -= $amount;
            }

            return $reservations;
        });
    }

    /**
     * Create a pending reservation against a specific payment.
     *
     * The created-refund event syncs pending_refunded_total; callers own
     * the ceiling and idempotency checks. The allocation key and the
     * transaction id start as the caller's request identity; completing the
     * refund replaces the transaction id with the provider identity.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function reservePendingRefund(
        Order $order,
        OrderPayment $payment,
        int $amount,
        string $allocationKey,
        string $reason,
        array $metadata,
    ): OrderRefund {
        return $order->refunds()->create([
            'payment_id' => $payment->getKey(),
            'gateway' => $payment->gateway,
            'transaction_id' => $allocationKey,
            'allocation_key' => $allocationKey,
            'amount' => $amount,
            'currency' => $payment->currency !== '' ? MoneyFormatter::normalizeCurrency($payment->currency) : MoneyFormatter::normalizeCurrency($order->currency),
            'status' => RefundStatus::Pending,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }

    public function claimPendingSubmission(OrderRefund $refund): bool
    {
        $order = $refund->order;

        if (! $order instanceof Order) {
            throw new RuntimeException('The refund must belong to an order.');
        }

        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        return DB::transaction(function () use ($refund): bool {
            // Lock order → refund everywhere, like every other refund path.
            $this->lockOrderRow($refund);

            /** @var OrderRefund|null $lockedRefund */
            $lockedRefund = $refund->newQuery()
                ->lockForUpdate()
                ->find($refund->getKey());

            if (! $lockedRefund instanceof OrderRefund
                || ! $lockedRefund->isPending()
                || $lockedRefund->hasProviderSubmissionStarted()) {
                return false;
            }

            $lockedRefund->provider_submission_started_at = CarbonImmutable::now();
            $lockedRefund->save();

            return true;
        });
    }

    public function completePending(OrderRefund $refund, ?string $transactionId = null): Order
    {
        $order = $refund->order;
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        return (new RefundCompleted($refund, $transactionId))->handle();
    }

    public function failPending(OrderRefund $refund, string $reason): OrderRefund
    {
        $order = $refund->order;
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        $failed = DB::transaction(function () use ($refund, $reason): OrderRefund {
            // Lock order → refund everywhere, like every other refund path.
            $lockedOrder = $this->lockOrderRow($refund);
            $lockedOrder->unsetRelation('refunds');

            /** @var OrderRefund $lockedRefund */
            $lockedRefund = $lockedOrder->refunds()
                ->whereKey($refund->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRefund->setRelation('order', $lockedOrder);

            if ($lockedRefund->isPending()) {
                $lockedRefund->markAsFailed($reason);

                // The status-change event already moved the totals; refresh so
                // any later reads in this request see fresh values.
                $lockedOrder->refresh();
            }

            // Report the persisted row: the caller's instance predates the
            // lock and would still read as pending to listeners.
            return $lockedRefund;
        });

        event(new OrderRefundFailed($order, $failed, $reason, $failed->metadata ?? []));

        return $failed;
    }

    /**
     * Lock the parent order row for a refund, preserving the shared
     * order → refund → payment lock order across every refund path.
     */
    private function lockOrderRow(OrderRefund $refund): Order
    {
        $order = $refund->order;

        if (! $order instanceof Order) {
            throw new RuntimeException('The refund must belong to an order.');
        }

        /** @var Order $lockedOrder */
        $lockedOrder = $order->newQuery()
            ->lockForUpdate()
            ->findOrFail($order->getKey());

        return $lockedOrder;
    }
}
