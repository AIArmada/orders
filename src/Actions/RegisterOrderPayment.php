<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\Orders\Actions\Concerns\AssertsOrderOwnerBoundary;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Transitions\PaymentConfirmed;

final class RegisterOrderPayment
{
    use AssertsOrderOwnerBoundary;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function execute(
        Order $order,
        string $transactionId,
        string $gateway,
        int $amount,
        array $metadata = [],
    ): Order {
        $this->assertOwnerBoundaryForMutation($order, __METHOD__);

        $transition = new PaymentConfirmed($order, $transactionId, $gateway, $amount, $metadata);

        return $transition->handle();
    }
}
