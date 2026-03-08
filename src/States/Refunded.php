<?php

declare(strict_types=1);

namespace AIArmada\Orders\States;

/**
 * Order has been fully refunded - terminal state.
 */
final class Refunded extends OrderStatus
{
    public static string $name = 'refunded';

    public function color(): string
    {
        return 'gray';
    }

    public function icon(): string
    {
        return 'heroicon-o-banknotes';
    }

    public function label(): string
    {
        return __('orders::states.refunded');
    }

    public function isFinal(): bool
    {
        return true;
    }
}
