<?php

declare(strict_types=1);

namespace AIArmada\Orders\States;

/**
 * Order has been shipped and is in transit.
 */
final class Shipped extends OrderStatus
{
    public static string $name = 'shipped';

    public function color(): string
    {
        return 'primary';
    }

    public function icon(): string
    {
        return 'heroicon-o-truck';
    }

    public function label(): string
    {
        return __('orders::states.shipped');
    }
}
