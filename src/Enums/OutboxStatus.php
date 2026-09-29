<?php

declare(strict_types=1);

namespace AIArmada\Orders\Enums;

/**
 * Relay lifecycle for an order outbox message.
 */
enum OutboxStatus: string
{
    case Pending = 'pending';
    case Relaying = 'relaying';
    case Relayed = 'relayed';
    case Failed = 'failed';
    case Dead = 'dead';
    case Suppressed = 'suppressed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Relaying => 'Relaying',
            self::Relayed => 'Relayed',
            self::Failed => 'Failed',
            self::Dead => 'Dead',
            self::Suppressed => 'Suppressed',
        };
    }
}
