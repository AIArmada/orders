<?php

declare(strict_types=1);

namespace AIArmada\Orders\Exceptions;

use InvalidArgumentException;

final class OrderNotAwaitingPayment extends InvalidArgumentException
{
    public static function forState(string $state): self
    {
        return new self(sprintf(
            'Only orders awaiting payment can be confirmed as free orders (current state: %s).',
            $state,
        ));
    }
}
