<?php

declare(strict_types=1);

namespace AIArmada\Orders\Transitions;

use AIArmada\Orders\Enums\PaymentStatus;
use AIArmada\Orders\Enums\RefundStatus;
use AIArmada\Orders\Events\OrderRefunded;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\States\Refunded;
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
        $now = now();

        // Find the original payment
        $payment = $this->order->payments()->where('status', PaymentStatus::Completed)->first();

        // Mark payment as refunded
        if ($payment !== null) {
            $payment->markAsRefunded();
        }

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

        // Update order state and refunded timestamp
        $this->order->refunded_at = $now;
        $this->order->status->transitionTo(Refunded::class);
        $this->order->save();

        // Dispatch event
        event(new OrderRefunded($this->order, $this->amount, $this->reason));

        return $this->order;
    }
}
