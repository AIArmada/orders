<?php

declare(strict_types=1);

namespace AIArmada\Orders\Contracts;

use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Support\PackageUnit;

interface PackageReturnVerifier
{
    public function receivedUnit(Order $order, string $authorizationId): PackageUnit;
}
