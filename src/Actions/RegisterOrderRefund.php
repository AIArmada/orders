<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\Orders\Actions\Concerns\AssertsOrderOwnerBoundary;
use AIArmada\Orders\Enums\PaymentStatus;
use AIArmada\Orders\Enums\RefundStatus;
use AIArmada\Orders\Events\OrderRefundFailed;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderRefund;
use AIArmada\Orders\Support\RefundAllocationValidator;
use AIArmada\Orders\Transitions\RefundCompleted;
use AIArmada\Orders\Transitions\RefundProcessed;
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
    ): Order {
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        if (mb_trim($transactionId) === '') {
            throw new InvalidArgumentException('A refund transaction ID is required.');
        }

        return DB::transaction(function () use ($order, $amount, $transactionId, $reason, $metadata): Order {
            // Keep the caller's model instance while locking the database row,
            // so retries cannot create duplicate refunds or overshoot the
            // remaining refundable amount.
            $order->newQuery()
                ->lockForUpdate()
                ->findOrFail($order->getKey());
            $order->refresh();

            $existingRefund = $order->refunds()
                ->where('transaction_id', $transactionId)
                ->whereIn('status', [RefundStatus::Pending, RefundStatus::Completed])
                ->first();

            if ($existingRefund instanceof OrderRefund) {
                if ($existingRefund->isPending()) {
                    throw new RuntimeException("Refund transaction {$transactionId} is already pending.");
                }

                return $order;
            }

            if (! $order->canBeRefunded()) {
                throw new RuntimeException("Order {$order->order_number} cannot be refunded in its current state.");
            }

            return (new RefundProcessed($order, $amount, $transactionId, $reason, $metadata))->handle();
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function createPending(
        Order $order,
        int $amount,
        string $transactionId,
        string $reason,
        array $metadata = [],
    ): OrderRefund {
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        if ($amount <= 0) {
            throw new InvalidArgumentException('Refund amount must be greater than zero.');
        }

        if (mb_trim($transactionId) === '') {
            throw new InvalidArgumentException('A refund transaction ID is required.');
        }

        RefundAllocationValidator::assertAmount($metadata, $amount);

        return DB::transaction(function () use ($order, $amount, $transactionId, $reason, $metadata): OrderRefund {
            $lockedOrder = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());

            // The transaction id is the caller's idempotency key. Check it
            // after locking the order so concurrent retries cannot create two
            // pending refunds, even when the first refund makes the order
            // unrefundable before the retry arrives.
            $existingRefund = $lockedOrder->refunds()
                ->where('transaction_id', $transactionId)
                ->whereIn('status', [RefundStatus::Pending, RefundStatus::Completed])
                ->first();

            if ($existingRefund instanceof OrderRefund) {
                return $existingRefund;
            }

            if (! $lockedOrder->canBeRefunded()) {
                throw new RuntimeException("Order {$lockedOrder->order_number} cannot be refunded in its current state.");
            }

            $remainingRefundable = $lockedOrder->getRemainingRefundable();

            if ($amount > $remainingRefundable) {
                throw new InvalidArgumentException(sprintf(
                    'Refund amount cannot exceed the remaining refundable amount of %d.',
                    $remainingRefundable,
                ));
            }

            $payment = $lockedOrder->payments()
                ->where('status', PaymentStatus::Completed)
                ->first();

            if ($payment === null) {
                throw new RuntimeException("Order {$lockedOrder->order_number} has no completed payment to refund.");
            }

            return $lockedOrder->refunds()->create([
                'payment_id' => $payment->getKey(),
                'gateway' => $payment->gateway,
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'currency' => $lockedOrder->currency,
                'status' => RefundStatus::Pending,
                'reason' => $reason,
                'metadata' => $metadata,
            ]);
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

        if (! $refund->isPending()) {
            return $refund;
        }

        $refund->markAsFailed($reason);
        event(new OrderRefundFailed($order, $refund, $reason, $refund->metadata ?? []));

        return $refund;
    }
}
