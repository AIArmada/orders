<?php

declare(strict_types=1);

namespace AIArmada\Orders\Transitions;

use AIArmada\Orders\Enums\PaymentStatus;
use AIArmada\Orders\Events\InventoryReleaseRequired;
use AIArmada\Orders\Events\OrderCanceled as OrderCanceledEvent;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\States\Canceled;
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
        // Update order state and canceled timestamp
        $this->order->status->transitionTo(Canceled::class);
        $this->order->canceled_at = now();
        $this->order->cancellation_reason = $this->reason;
        $this->order->save();

        // Add cancellation note
        $this->order->orderNotes()->create([
            'user_id' => $this->canceledBy,
            'content' => "Order canceled: {$this->reason}",
            'is_customer_visible' => true,
        ]);

        // Release inventory reservations (if applicable)
        if (config('orders.integrations.inventory.enabled', true)) {
            $this->releaseInventory();
        }

        // Issue refund if order was paid
        if ($this->issueRefund && $this->order->isPaid()) {
            $this->initiateRefund();
        }

        // Dispatch event
        event(new OrderCanceledEvent($this->order, $this->reason, $this->canceledBy));

        return $this->order;
    }

    protected function releaseInventory(): void
    {
        event(new InventoryReleaseRequired($this->order));
    }

    protected function initiateRefund(): void
    {
        // Create pending refund record
        $totalPaid = $this->order->getTotalPaid();
        if ($totalPaid > 0) {
            $payment = $this->order->payments()->where('status', PaymentStatus::Completed)->first();
            if ($payment) {
                $this->order->refunds()->create([
                    'payment_id' => $payment->id,
                    'gateway' => $payment->gateway,
                    'amount' => $totalPaid,
                    'currency' => $this->order->currency,
                    'status' => PaymentStatus::Pending,
                    'reason' => 'Order canceled: ' . $this->reason,
                ]);
            }
        }
    }
}
