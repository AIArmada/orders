<?php

declare(strict_types=1);

namespace AIArmada\Orders\Transitions;

use AIArmada\Orders\Enums\PaymentStatus;
use AIArmada\Orders\Enums\RefundStatus;
use AIArmada\Orders\Events\OrderRefunded;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderRefund;
use AIArmada\Orders\States\Refunded;
use AIArmada\Orders\Support\RefundAllocationValidator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Spatie\ModelStates\Transition;

/**
 * Complete a provider-confirmed pending refund.
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
            /** @var OrderRefund $refund */
            $refund = $this->refund->newQuery()
                ->lockForUpdate()
                ->findOrFail($this->refund->getKey());

            $order = $refund->order;

            if (! $order instanceof Order) {
                throw new RuntimeException('The refund must belong to an order.');
            }

            if ($refund->status === RefundStatus::Completed) {
                return $order;
            }

            if ($refund->status !== RefundStatus::Pending) {
                throw new RuntimeException('Only pending refunds can be completed.');
            }

            /** @var Order $lockedOrder */
            $lockedOrder = $order->newQuery()
                ->lockForUpdate()
                ->findOrFail($order->getKey());
            $refund->setRelation('order', $lockedOrder);
            $order = $lockedOrder;

            $amount = (int) $refund->amount;
            $metadata = $refund->metadata ?? [];

            if ($amount <= 0) {
                throw new InvalidArgumentException('Refund amount must be greater than zero.');
            }

            RefundAllocationValidator::assertAmount($metadata, $amount);

            $refund->markAsCompleted($this->transactionId);
            $order->unsetRelation('refunds');

            $refundCeiling = $order->getTotalPaid() > 0
                ? $order->getTotalPaid()
                : (int) $order->grand_total;

            if ($order->getTotalRefunded() >= $refundCeiling) {
                $payment = $refund->payment
                    ?? $order->payments()->where('status', PaymentStatus::Completed)->first();

                if ($payment !== null) {
                    $payment->markAsRefunded();
                }

                if (! ($order->status instanceof Refunded)) {
                    $order->status->transitionTo(Refunded::class);
                }

                $order->refunded_at ??= now();
                $order->save();
            }

            event(new OrderRefunded($order, $amount, $refund->reason, $metadata));

            return $order;
        });
    }
}
