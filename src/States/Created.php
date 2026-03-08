<?php

declare(strict_types=1);

namespace AIArmada\Orders\States;

/**
 * Initial state when order is first created.
 */
final class Created extends OrderStatus
{
    public static string $name = 'created';

    public function color(): string
    {
        return 'gray';
    }

    public function icon(): string
    {
        return 'heroicon-o-plus-circle';
    }

    public function label(): string
    {
        return __('orders::states.created');
    }

    public function canCancel(): bool
    {
        return true;
    }

    public function canModify(): bool
    {
        return true;
    }
}
