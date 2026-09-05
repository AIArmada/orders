<?php

declare(strict_types=1);

namespace AIArmada\Orders\Transitions;

use AIArmada\Orders\Enums\PaymentStatus;
use AIArmada\Orders\Enums\RefundStatus;
use AIArmada\Orders\Events\OrderRefunded;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\States\Refunded;
use AIArmada\Orders\Support\RefundAllocationValidator;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Spatie\ModelStates\Transition;

/**
 * Transition from Returned → Refunded.
 *
 * This transition is triggered when a refund is processed for returned items.
 */
final class RefundProcessed extends Transition
{
    public function __construct(
        private Order $order,
        private int $amount,
        private string $transactionId,
        private string $reason,
        /** @var array<string, mixed> */
        private array $metadata = [],
    ) {}

    public function handle(): Order
    {
        $now = CarbonImmutable::now();

        if ($this->amount <= 0) {
            throw new InvalidArgumentException('Refund amount must be greater than zero.');
        }

        RefundAllocationValidator::assertAmount($this->metadata, $this->amount);

        $totalPaid = $this->order->getTotalPaid();
        $refundCeiling = $totalPaid > 0
            ? $totalPaid
            : (int) $this->order->grand_total;
        $remainingRefundable = $this->order->getRemainingRefundable();

        if ($this->amount > $remainingRefundable) {
            throw new InvalidArgumentException(sprintf(
                'Refund amount cannot exceed the remaining refundable amount of %d.',
                $remainingRefundable,
            ));
        }

        // Find the original payment
        $payment = $this->order->payments()->where('status', PaymentStatus::Completed)->first();

        // Record refund
        $this->order->refunds()->create([
            'payment_id' => $payment?->id,
            'gateway' => $payment?->gateway ?? 'manual',
            'transaction_id' => $this->transactionId,
            'amount' => $this->amount,
            'currency' => $this->order->currency,
            'status' => RefundStatus::Completed,
            'reason' => $this->reason,
            'refunded_at' => $now,
            'metadata' => $this->metadata,
        ]);
        $this->order->unsetRelation('refunds');

        $isFullyRefunded = $this->order->getTotalRefunded() >= $refundCeiling;

        // Keep the payment and order open for additional partial refunds. The
        // order becomes terminal only after the whole paid amount is returned.
        if ($isFullyRefunded) {
            if ($payment !== null) {
                $payment->markAsRefunded();
            }

            $this->order->refunded_at = $now;
            $this->order->status->transitionTo(Refunded::class);
        }

        $this->order->save();

        // Dispatch event
        event(new OrderRefunded($this->order, $this->amount, $this->reason, $this->metadata));

        return $this->order;
    }
}
