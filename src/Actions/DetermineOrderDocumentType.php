<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\Docs\Enums\DocType;
use AIArmada\Orders\Models\Order;

final class DetermineOrderDocumentType
{
    public function execute(Order $order): DocType
    {
        if ($order->isPaid() || $order->payments()->exists()) {
            return DocType::Receipt;
        }

        return DocType::Invoice;
    }
}
