<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\Orders\Actions\Concerns\AssertsOrderOwnerBoundary;
use AIArmada\Orders\Models\Order;

final readonly class MergeOrderMetadata
{
    use AssertsOrderOwnerBoundary;

    /** @param array<string, mixed> $patch */
    public function handle(string $orderId, array $patch): void
    {
        (new Order)->getConnection()->transaction(function () use ($orderId, $patch): void {
            $order = Order::query()->forOwner()->lockForUpdate()->findOrFail($orderId);
            $this->assertOwnerBoundaryForMutation($order, 'merge order metadata');

            $current = is_array($order->metadata) ? $order->metadata : [];
            $order->update(['metadata' => array_replace($current, $patch)]);
        }, attempts: 3);
    }
}
