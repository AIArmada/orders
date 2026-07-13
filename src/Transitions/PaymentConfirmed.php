<?php

declare(strict_types=1);

namespace AIArmada\Orders\Transitions;

use AIArmada\Orders\Enums\PaymentStatus;
use AIArmada\Orders\Events\CommissionAttributionRequired;
use AIArmada\Orders\Events\OrderPaid;
use AIArmada\Orders\Events\OrderProcessingStarted;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderPayment;
use AIArmada\Orders\States\Processing;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\ModelStates\Transition;

/**
 * Transition from PendingPayment → Processing.
 *
 * This transition is triggered when payment is confirmed.
 * It records the payment, triggers optional integrations
 * (inventory deduction, affiliate commission), and updates the order state.
 */
final class PaymentConfirmed extends Transition
{
    public function __construct(
        private Order $order,
        private string $transactionId,
        private string $gateway,
        private int $amount,
        /** @var array<string, mixed> */
        private array $metadata = [],
    ) {}

    public function handle(): Order
    {
        $originalOrder = $this->order;

        return DB::transaction(function () use ($originalOrder): Order {
            $this->order = $this->order->newQuery()
                ->lockForUpdate()
                ->findOrFail($this->order->getKey());

            $existingPayment = $this->order->payments()
                ->where('gateway', $this->gateway)
                ->where('transaction_id', $this->transactionId)
                ->first();

            if ($existingPayment !== null) {
                return $this->handleExistingPayment($existingPayment, $originalOrder);
            }

            try {
                // Record payment
                $this->order->payments()->create([
                    'transaction_id' => $this->transactionId,
                    'gateway' => $this->gateway,
                    'amount' => $this->amount,
                    'currency' => $this->order->currency,
                    'status' => PaymentStatus::Completed,
                    'paid_at' => now(),
                    'metadata' => $this->metadata,
                ]);
            } catch (QueryException $e) {
                if (! $this->isDuplicateKeyError($e)) {
                    throw $e;
                }

                // Race condition: another caller inserted the same identity.
                // Reload and validate rather than re-inserting.
                $existingPayment = $this->order->payments()
                    ->where('gateway', $this->gateway)
                    ->where('transaction_id', $this->transactionId)
                    ->first();

                if ($existingPayment === null) {
                    throw $e;
                }

                return $this->handleExistingPayment($existingPayment, $originalOrder);
            }

            // Attribute affiliate commission (if package present)
            if (config('orders.integrations.affiliates.enabled', true)) {
                $this->attributeCommission();
            }

            // Update order state and paid timestamp
            $this->order->status->transitionTo(Processing::class);
            $this->order->paid_at = now();
            $this->order->save();

            $this->syncOriginalOrder($originalOrder);

            // Dispatch events only after the outer transaction commits
            $order = $this->order;
            $transactionId = $this->transactionId;
            $gateway = $this->gateway;

            DB::afterCommit(function () use ($order, $transactionId, $gateway): void {
                event(new OrderPaid($order, $transactionId, $gateway));
                event(new OrderProcessingStarted($order, $transactionId, $gateway));
            });

            return $originalOrder;
        });
    }

    private function handleExistingPayment(OrderPayment $existingPayment, Order $originalOrder): Order
    {
        if (
            (int) $existingPayment->amount !== $this->amount
            || (string) $existingPayment->currency !== (string) $this->order->currency
        ) {
            throw new InvalidArgumentException(sprintf(
                'Payment identity %s/%s was already recorded with a different amount or currency.',
                $this->gateway,
                $this->transactionId,
            ));
        }

        $this->syncOriginalOrder($originalOrder);

        return $originalOrder;
    }

    private function isDuplicateKeyError(QueryException $e): bool
    {
        $sqlState = (string) $e->getPrevious()?->getCode();

        // 23000 = MySQL integrity violation, 23505 = PostgreSQL unique violation
        return $sqlState === '23000' || $sqlState === '23505';
    }

    private function syncOriginalOrder(Order $originalOrder): void
    {
        $originalOrder->setRawAttributes($this->order->getAttributes());
        $originalOrder->setRelations($this->order->getRelations());
        $originalOrder->syncOriginal();
    }

    /**
     * Attribute affiliate commission.
     */
    protected function attributeCommission(): void
    {
        event(new CommissionAttributionRequired($this->order));
    }
}
