<?php

declare(strict_types=1);

namespace AIArmada\Orders\Transitions;

use AIArmada\Orders\Events\OrderShipped;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\States\Shipped;
use Illuminate\Support\Arr;
use Spatie\ModelStates\Transition;

/**
 * Transition from Processing → Shipped.
 *
 * This transition is triggered when a shipment is created.
 * It records the shipping details and updates the order state.
 */
final class ShipmentCreated extends Transition
{
    public function __construct(
        private Order $order,
        private string $carrier,
        private string $trackingNumber,
        private ?string $shipmentId = null,
        /** @var array<string, mixed> */
        private array $metadata = [],
    ) {}

    public function handle(): Order
    {
        $shippedAt = now();

        $existingMetadata = $this->order->metadata ?? [];
        if (! is_array($existingMetadata)) {
            $existingMetadata = [];
        }

        $existingShipping = Arr::get($existingMetadata, 'shipping', []);
        if (! is_array($existingShipping)) {
            $existingShipping = [];
        }

        $existingShippingMetadata = Arr::get($existingShipping, 'metadata', []);
        if (! is_array($existingShippingMetadata)) {
            $existingShippingMetadata = [];
        }

        $shipping = array_merge($existingShipping, [
            'carrier' => $this->carrier,
            'tracking_number' => $this->trackingNumber,
            'shipment_id' => $this->shipmentId,
            'shipped_at' => $shippedAt->toIso8601String(),
        ]);

        if ($this->metadata !== []) {
            $shipping['metadata'] = array_merge($existingShippingMetadata, $this->metadata);
        }

        $existingMetadata['shipping'] = $shipping;
        $this->order->metadata = $existingMetadata;
        $this->order->shipped_at = $shippedAt;

        $this->order->status->transitionTo(Shipped::class);

        // Dispatch event
        event(new OrderShipped(
            $this->order,
            $this->carrier,
            $this->trackingNumber,
            $this->shipmentId
        ));

        return $this->order;
    }
}
