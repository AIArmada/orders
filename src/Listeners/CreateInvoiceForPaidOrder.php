<?php

declare(strict_types=1);

namespace AIArmada\Orders\Listeners;

use AIArmada\Orders\Actions\CreateOrderInvoiceDoc;
use AIArmada\Orders\Events\OrderPaid;

final class CreateInvoiceForPaidOrder
{
    public function __construct(
        private CreateOrderInvoiceDoc $creator
    ) {}

    public function handle(OrderPaid $event): void
    {
        if (! config('orders.integrations.docs.enabled', true)) {
            return;
        }

        $this->creator->execute($event->order, $event->transactionId, $event->gateway);
    }
}
