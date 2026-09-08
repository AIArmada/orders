<?php

declare(strict_types=1);

namespace AIArmada\Orders\Listeners;

use AIArmada\Orders\Events\InventoryReleaseRequired;
use AIArmada\Orders\Events\OrderCancelInitiated;
use Illuminate\Contracts\Queue\ShouldQueue;

final class ReleaseInventoryOnOrderCanceled implements ShouldQueue
{
    public function handle(OrderCancelInitiated $event): void
    {
        if (! config('orders.integrations.inventory.enabled', true)) {
            return;
        }

        event(new InventoryReleaseRequired($event->order));
    }
}
