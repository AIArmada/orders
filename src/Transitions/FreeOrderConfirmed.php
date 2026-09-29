<?php

declare(strict_types=1);

namespace AIArmada\Orders\Transitions;

use AIArmada\Orders\Events\OrderFulfillmentRequired;
use AIArmada\Orders\Events\OrderProcessingStarted;
use AIArmada\Orders\Exceptions\OrderNotAwaitingPayment;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\States\Created;
use AIArmada\Orders\States\PendingPayment;
use AIArmada\Orders\States\Processing;
use AIArmada\Orders\Support\OrderOutbox;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\ModelStates\Transition;

final class FreeOrderConfirmed extends Transition
{
    public function __construct(
        private Order $order,
    ) {}

    public function handle(): Order
    {
        return DB::transaction(function (): Order {
            $order = $this->order->newQuery()
                ->lockForUpdate()
                ->findOrFail($this->order->getKey());

            // Money guards first: "free" means nothing owed AND nothing
            // paid. A zero balance alone is not enough — a fully paid
            // order also owes nothing.
            if ((int) $order->grand_total > 0) {
                throw new InvalidArgumentException('Only orders with no total can be confirmed as free orders.');
            }

            if ($order->getTotalPaid() !== 0) {
                throw new InvalidArgumentException('Only unpaid orders can be confirmed as free orders.');
            }

            if ($order->status->equals(Processing::class)) {
                $this->syncCallerOrder($order);

                return $order;
            }

            if (! $order->status->equals(Created::class) && ! $order->status->equals(PendingPayment::class)) {
                throw OrderNotAwaitingPayment::forState(class_basename($order->status));
            }

            $order->status->transitionTo(Processing::class);
            $order->save();

            $this->syncCallerOrder($order);

            // Stage outbox rows in-transaction so a crash between commit and
            // dispatch stays recoverable.
            $outboxIds = [
                OrderOutbox::stage($order, OrderProcessingStarted::class, (string) $order->getKey(), 'free'),
                OrderOutbox::stage($order, OrderFulfillmentRequired::class, (string) $order->getKey(), 'free'),
            ];

            DB::afterCommit(function () use ($order, $outboxIds): void {
                OrderOutbox::dispatchReplayable($order, $outboxIds, static function () use ($order): void {
                    event(new OrderProcessingStarted($order, (string) $order->getKey(), 'free'));
                    event(new OrderFulfillmentRequired($order, (string) $order->getKey(), 'free'));
                });
            });

            return $order;
        });
    }

    /**
     * Mirror the locked copy back onto the caller's instance so code
     * holding the original model does not read stale state.
     */
    private function syncCallerOrder(Order $order): void
    {
        $this->order->setRawAttributes($order->getAttributes());
        $this->order->setRelations($order->getRelations());
        $this->order->syncOriginal();
    }
}
