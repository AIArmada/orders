<?php

declare(strict_types=1);

namespace AIArmada\Orders\Events\Concerns;

use AIArmada\Orders\Models\Order;

trait HasOrderOwnerTuple
{
    public ?string $owner_type = null;

    public ?string $owner_id = null;

    public bool $owner_is_global = true;

    protected function hydrateOrderOwnerTuple(Order $order): void
    {
        $this->owner_type = $order->owner_type;
        $this->owner_id = $order->owner_id;
        $this->owner_is_global = $order->owner_type === null && $order->owner_id === null;
    }
}
