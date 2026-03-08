<?php

declare(strict_types=1);

namespace AIArmada\Orders\States;

/**
 * Awaiting payment confirmation from customer.
 */
final class PendingPayment extends OrderStatus
{
    public static string $name = 'pending_payment';

    public function color(): string
    {
        return 'warning';
    }

    public function icon(): string
    {
        return 'heroicon-o-clock';
    }

    public function label(): string
    {
        return __('orders::states.pending_payment');
    }

    public function canCancel(): bool
    {
        return true; // Customer can cancel before paying
    }

    public function canModify(): bool
    {
        return true; // Order can still be edited
    }
}
