<?php

declare(strict_types=1);

namespace AIArmada\Orders\Listeners;

use AIArmada\Orders\Events\InventoryDeductionRequired;
use AIArmada\Orders\Events\OrderProcessingStarted;

final class DeductInventoryOnPaymentConfirmed
{
    public function handle(OrderProcessingStarted $event): void
    {
        if (! config('orders.integrations.inventory.enabled', true)) {
            return;
        }

        event(new InventoryDeductionRequired($event->order));
    }
}
