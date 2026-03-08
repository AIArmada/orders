<?php

declare(strict_types=1);

namespace AIArmada\Orders\Contracts;

use AIArmada\Orders\Models\Order;

/**
 * Contract for fulfillment/shipping integration.
 *
 * Implement this interface to connect orders with shipping management.
 *
 * The shipping package provides a ready-to-use implementation:
 *
 * @see \AIArmada\Shipping\Integrations\OrderFulfillmentHandler
 *
 * This implementation is automatically registered when both the orders
 * and shipping packages are installed together.
 */
interface FulfillmentHandler
{
    /**
     * Create a shipment for an order.
     *
     * @param  array<string, mixed>  $shipmentData  Carrier, service, etc.
     * @return array{success: bool, shipment_id: ?string, tracking_number: ?string, error: ?string}
     */
    public function createShipment(Order $order, array $shipmentData): array;

    /**
     * Get shipping rates for an order.
     *
     * @return array<array{carrier: string, service: string, rate: int, currency: string}>
     */
    public function getRates(Order $order): array;

    /**
     * Get tracking info for a shipment.
     *
     * @return array{status: string, events: array<array{date: string, description: string, location: ?string}>}
     */
    public function getTracking(string $trackingNumber): array;
}
