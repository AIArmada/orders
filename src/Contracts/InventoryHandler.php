<?php

declare(strict_types=1);

namespace AIArmada\Orders\Contracts;

use AIArmada\Orders\Models\Order;

/**
 * Contract for inventory integration.
 *
 * Implement this interface to connect orders with inventory management.
 */
interface InventoryHandler
{
    /**
     * Reserve inventory for an order.
     */
    public function reserveInventory(Order $order): bool;

    /**
     * Deduct inventory for a paid order.
     */
    public function deductInventory(Order $order): bool;

    /**
     * Release reserved inventory (on cancellation).
     */
    public function releaseInventory(Order $order): bool;

    /**
     * Check if all items are in stock.
     */
    public function checkAvailability(Order $order): bool;
}
