<?php

declare(strict_types=1);

namespace AIArmada\Orders\States;

/**
 * Payment received, order is being prepared for fulfillment.
 */
final class Processing extends OrderStatus
{
    public static string $name = 'processing';

    public function color(): string
    {
        return 'info';
    }

    public function icon(): string
    {
        return 'heroicon-o-cog-6-tooth';
    }

    public function label(): string
    {
        return __('orders::states.processing');
    }

    public function canCancel(): bool
    {
        return true; // Can still cancel before shipping
    }
}
