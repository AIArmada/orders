<?php

declare(strict_types=1);

namespace AIArmada\Orders\Transitions;

use AIArmada\Orders\Events\OrderCompleted as OrderCompletedEvent;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\States\Completed;
use Illuminate\Support\Arr;
use Spatie\ModelStates\Transition;

/**
 * Transition from Processing → Completed.
 *
 * This transition is used when an order's fulfillment lifecycle is complete
 * without requiring a shipping / delivery path.
 */
final class OrderCompleted extends Transition
{
    public function __construct(
        private Order $order,
        /** @var array<string, mixed> */
        private array $metadata = [],
    ) {}

    public function handle(): Order
    {
        $completedAt = now();

        $existingMetadata = $this->order->metadata ?? [];
        if (! is_array($existingMetadata)) {
            $existingMetadata = [];
        }

        $existingCompletion = Arr::get($existingMetadata, 'completion', []);
        if (! is_array($existingCompletion)) {
            $existingCompletion = [];
        }

        $existingCompletionMetadata = Arr::get($existingCompletion, 'metadata', []);
        if (! is_array($existingCompletionMetadata)) {
            $existingCompletionMetadata = [];
        }

        $completion = array_merge($existingCompletion, [
            'completed_at' => $completedAt->toIso8601String(),
        ]);

        if ($this->metadata !== []) {
            $completion['metadata'] = array_merge($existingCompletionMetadata, $this->metadata);
        }

        $existingMetadata['completion'] = $completion;
        $this->order->metadata = $existingMetadata;
        $this->order->status->transitionTo(Completed::class);

        event(new OrderCompletedEvent($this->order));

        return $this->order;
    }
}
