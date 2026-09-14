<?php

declare(strict_types=1);

namespace AIArmada\Orders\Exceptions;

use RuntimeException;

final class OrderIntakeConflictException extends RuntimeException
{
    public static function duplicate(
        string $intakeSource,
        string $intakeId,
    ): self {
        return new self(sprintf(
            'Duplicate order intake: source [%s] with id [%s] already exists with different data.',
            $intakeSource,
            $intakeId,
        ));
    }
}
