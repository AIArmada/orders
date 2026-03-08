<?php

declare(strict_types=1);

namespace AIArmada\Orders\Contracts;

use AIArmada\Orders\Models\Order;

/**
 * Contract for order payment handling.
 *
 * Implement this interface in your payment package to integrate with orders.
 */
interface PaymentHandler
{
    /**
     * Process payment for an order.
     *
     * @param  array<string, mixed>  $paymentData  Payment method data
     * @return array{success: bool, transaction_id: ?string, error: ?string}
     */
    public function processPayment(Order $order, array $paymentData): array;

    /**
     * Process refund for an order.
     *
     * @return array{success: bool, transaction_id: ?string, error: ?string}
     */
    public function processRefund(Order $order, int $amount, string $reason): array;

    /**
     * Get available payment methods.
     *
     * @return array<string, array{name: string, icon: ?string}>
     */
    public function getPaymentMethods(): array;
}
