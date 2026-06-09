<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions\Concerns;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Orders\Models\Order;
use RuntimeException;

trait AssertsOrderOwnerBoundary
{
    private function assertOwnerBoundaryForMutation(Order $order, string $operation): void
    {
        if (! (bool) config('orders.owner.enabled', true)) {
            return;
        }

        $owner = OwnerContext::resolve();

        if ($order->hasOwner()) {
            if ($owner === null) {
                throw new RuntimeException(sprintf(
                    'A matching owner context is required for %s when mutating owned orders. Use OwnerContext::withOwner($owner, ...).',
                    $operation,
                ));
            }

            if (! $order->belongsToOwner($owner)) {
                throw new RuntimeException(sprintf(
                    'Cross-owner mutation blocked for %s. The current owner context does not match the order owner.',
                    $operation,
                ));
            }

            return;
        }

        OwnerContext::assertResolvedOrExplicitGlobal(
            $owner,
            sprintf(
                'Explicit global owner context is required for %s when mutating global orders. Use OwnerContext::withOwner(null, ...).',
                $operation,
            ),
        );

        if (! OwnerContext::isExplicitGlobal()) {
            throw new RuntimeException(sprintf(
                'Explicit global owner context is required for %s when mutating global orders. Use OwnerContext::withOwner(null, ...).',
                $operation,
            ));
        }
    }
}
