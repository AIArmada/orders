<?php

declare(strict_types=1);

namespace AIArmada\Orders\Transitions;

use AIArmada\Orders\Events\OrderDelivered;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\States\Delivered;
use Illuminate\Support\Arr;
use Spatie\ModelStates\Transition;

/**
 * Transition from Shipped → Delivered.
 *
 * This transition is triggered when delivery is confirmed.
 */
final class DeliveryConfirmed extends Transition
{
    public function __construct(
        private Order $order,
        /** @var array<string, mixed> */
        private array $metadata = [],
    ) {}

    public function handle(): Order
    {
        $deliveredAt = now();

        $existingMetadata = $this->order->metadata ?? [];
        if (! is_array($existingMetadata)) {
            $existingMetadata = [];
        }

        $existingShipping = Arr::get($existingMetadata, 'shipping', []);
        if (! is_array($existingShipping)) {
            $existingShipping = [];
        }

        $existingDeliveryMetadata = Arr::get($existingShipping, 'delivery_metadata', []);
        if (! is_array($existingDeliveryMetadata)) {
            $existingDeliveryMetadata = [];
        }

        $shipping = array_merge($existingShipping, [
            'delivered_at' => $deliveredAt->toIso8601String(),
        ]);

        if ($this->metadata !== []) {
            $shipping['delivery_metadata'] = array_merge($existingDeliveryMetadata, $this->metadata);
        }

        $existingMetadata['shipping'] = $shipping;
        $this->order->metadata = $existingMetadata;
        $this->order->delivered_at = $deliveredAt;

        $this->order->status->transitionTo(Delivered::class);

        // Dispatch event
        event(new OrderDelivered($this->order));

        return $this->order;
    }
}
