<?php

declare(strict_types=1);

namespace AIArmada\Orders\Support;

/**
 * Retry tuning for the outbox relay, read once per run so the
 * attempt policy travels as one value instead of three loose ints.
 */
final readonly class OutboxRelayOptions
{
    public function __construct(
        public int $maxAttempts,
        public int $retryBaseSeconds,
        public int $retryMaxSeconds,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            maxAttempts: (int) config('orders.outbox.max_attempts', 10),
            retryBaseSeconds: (int) config('orders.outbox.retry_base_seconds', 60),
            retryMaxSeconds: (int) config('orders.outbox.retry_max_seconds', 3600),
        );
    }
}
