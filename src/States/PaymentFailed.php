<?php

declare(strict_types=1);

namespace AIArmada\Orders\States;

/**
 * Payment failed - terminal state.
 */
final class PaymentFailed extends OrderStatus
{
    public static string $name = 'payment_failed';

    public function color(): string
    {
        return 'danger';
    }

    public function icon(): string
    {
        return 'heroicon-o-x-mark';
    }

    public function label(): string
    {
        return __('orders::states.payment_failed');
    }

    public function isFinal(): bool
    {
        return true;
    }
}
