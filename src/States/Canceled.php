<?php

declare(strict_types=1);

namespace AIArmada\Orders\States;

/**
 * Order canceled by customer or admin - terminal state.
 */
final class Canceled extends OrderStatus
{
    public static string $name = 'canceled';

    public function color(): string
    {
        return 'gray';
    }

    public function icon(): string
    {
        return 'heroicon-o-x-circle';
    }

    public function label(): string
    {
        return __('orders::states.canceled');
    }

    public function isFinal(): bool
    {
        return true;
    }
}
