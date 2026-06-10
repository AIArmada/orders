<?php

declare(strict_types=1);

namespace AIArmada\Orders\Transitions;

use AIArmada\Orders\Enums\PaymentStatus;
use AIArmada\Orders\Events\OrderPaymentFailed;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\States\PaymentFailed as PaymentFailedState;
use Spatie\ModelStates\Transition;

/**
 * Transition from PendingPayment → PaymentFailed.
 *
 * This transition is triggered when payment fails for an order.
 */
final class PaymentFailed extends Transition
{
    public function __construct(
        private Order $order,
        private string $reason,
    ) {}

    public function handle(): Order
    {
        $now = now();

        // Mark the payment record as failed
        $payment = $this->order->payments()->where('status', PaymentStatus::Pending)->first();
        if ($payment !== null) {
            $payment->markAsFailed($this->reason);
        }

        // Update order state and payment_failed timestamp
        $this->order->payment_failed_at = $now;
        $this->order->status->transitionTo(PaymentFailedState::class);
        $this->order->save();

        // Dispatch event
        event(new OrderPaymentFailed($this->order, $this->reason));

        return $this->order;
    }
}
