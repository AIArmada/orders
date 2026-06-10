<?php

declare(strict_types=1);

namespace AIArmada\Orders\Enums;

/**
 * Item-level lifecycle status for OrderItem.
 */
enum OrderItemStatus: string
{
    case Active = 'active';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Returned = 'returned';
    case Canceled = 'canceled';
    case Backordered = 'backordered';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Shipped => 'Shipped',
            self::Delivered => 'Delivered',
            self::Returned => 'Returned',
            self::Canceled => 'Canceled',
            self::Backordered => 'Backordered',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'primary',
            self::Shipped => 'info',
            self::Delivered => 'success',
            self::Returned => 'warning',
            self::Canceled => 'danger',
            self::Backordered => 'gray',
        };
    }

    public function isFinal(): bool
    {
        return match ($this) {
            self::Active, self::Shipped, self::Backordered => false,
            self::Delivered, self::Returned, self::Canceled => true,
        };
    }
}
