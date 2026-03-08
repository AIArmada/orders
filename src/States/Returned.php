<?php

declare(strict_types=1);

namespace AIArmada\Orders\States;

/**
 * Order items have been returned by customer.
 */
final class Returned extends OrderStatus
{
    public static string $name = 'returned';

    public function color(): string
    {
        return 'warning';
    }

    public function icon(): string
    {
        return 'heroicon-o-arrow-uturn-left';
    }

    public function label(): string
    {
        return __('orders::states.returned');
    }

    public function canRefund(): bool
    {
        return true; // Returned items should be refunded
    }
}
