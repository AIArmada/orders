<?php

declare(strict_types=1);

namespace AIArmada\Orders\States;

/**
 * Fraud detected - terminal state requiring investigation.
 */
final class Fraud extends OrderStatus
{
    public static string $name = 'fraud';

    public function color(): string
    {
        return 'danger';
    }

    public function icon(): string
    {
        return 'heroicon-o-exclamation-triangle';
    }

    public function label(): string
    {
        return __('orders::states.fraud');
    }

    public function isFinal(): bool
    {
        return true;
    }
}
