<?php

declare(strict_types=1);

namespace AIArmada\Orders\States;

/**
 * Order has been delivered to the customer.
 */
final class Delivered extends OrderStatus
{
    public static string $name = 'delivered';

    public function color(): string
    {
        return 'success';
    }

    public function icon(): string
    {
        return 'heroicon-o-check';
    }

    public function label(): string
    {
        return __('orders::states.delivered');
    }

    public function canRefund(): bool
    {
        return true; // Customer can request refund after delivery
    }
}
