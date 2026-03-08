<?php

declare(strict_types=1);

namespace AIArmada\Orders\States;

/**
 * Order is on hold pending manual review.
 */
final class OnHold extends OrderStatus
{
    public static string $name = 'on_hold';

    public function color(): string
    {
        return 'gray';
    }

    public function icon(): string
    {
        return 'heroicon-o-pause-circle';
    }

    public function label(): string
    {
        return __('orders::states.on_hold');
    }

    public function canCancel(): bool
    {
        return true;
    }
}
