<?php

declare(strict_types=1);

namespace AIArmada\Orders\States;

/**
 * Order successfully completed - terminal state.
 */
final class Completed extends OrderStatus
{
    public static string $name = 'completed';

    public function color(): string
    {
        return 'success';
    }

    public function icon(): string
    {
        return 'heroicon-o-check-circle';
    }

    public function label(): string
    {
        return __('orders::states.completed');
    }

    public function isFinal(): bool
    {
        return true;
    }

    public function canRefund(): bool
    {
        return true; // Refunds still possible after completion
    }
}
