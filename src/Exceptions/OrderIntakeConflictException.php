<?php

declare(strict_types=1);

namespace AIArmada\Orders\Exceptions;

use RuntimeException;

final class OrderIntakeConflictException extends RuntimeException
{
    public static function duplicate(
        string $intakeSource,
        string $intakeId,
        string $existingOrderId,
    ): self {
        return new self(sprintf(
            'Duplicate order intake: source [%s] with id [%s] already exists as order [%s] with different data.',
            $intakeSource,
            $intakeId,
            $existingOrderId,
        ));
    }
}
