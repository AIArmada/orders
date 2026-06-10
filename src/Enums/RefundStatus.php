<?php

declare(strict_types=1);

namespace AIArmada\Orders\Enums;

/**
 * Refund status enum for OrderRefund.
 */
enum RefundStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
        };
    }

    public function isFinal(): bool
    {
        return match ($this) {
            self::Pending => false,
            self::Completed, self::Failed => true,
        };
    }
}
