<?php

declare(strict_types=1);

namespace AIArmada\Orders\Listeners;

use AIArmada\Orders\Events\InventoryReleaseRequired;
use AIArmada\Orders\Events\OrderCancelInitiated;

final class ReleaseInventoryOnOrderCanceled
{
    public function handle(OrderCancelInitiated $event): void
    {
        if (! config('orders.integrations.inventory.enabled', true)) {
            return;
        }

        event(new InventoryReleaseRequired($event->order));
    }
}
