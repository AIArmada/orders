<?php

declare(strict_types=1);

namespace AIArmada\Orders\Listeners;

use AIArmada\Orders\Events\CommissionAttributionRequired;
use AIArmada\Orders\Events\OrderFulfillmentRequired;

final class AttributeCommissionOnFulfillment
{
    public function handle(OrderFulfillmentRequired $event): void
    {
        if (! config('orders.integrations.affiliates.enabled', true)) {
            return;
        }

        event(new CommissionAttributionRequired($event->order));
    }
}
