<?php

declare(strict_types=1);

namespace AIArmada\Orders\Enums;

/**
 * Payment status enum for OrderPayment and OrderRefund.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Refunded => 'Refunded',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Refunded => 'gray',
        };
    }

    public function isFinal(): bool
    {
        return match ($this) {
            self::Pending => false,
            self::Completed, self::Failed, self::Refunded => true,
        };
    }
}
