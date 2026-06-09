<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\Orders\Models\Order;
use Illuminate\Database\Eloquent\Model;

final class CreateOrderFromCart
{
    public function __construct(
        private readonly CreateOrder $createOrder,
    ) {}

    /**
     * @param  array<string, mixed>|null  $billingAddress
     * @param  array<string, mixed>|null  $shippingAddress
     */
    public function execute(
        object $cart,
        Model $customer,
        ?array $billingAddress = null,
        ?array $shippingAddress = null,
    ): Order {
        $orderData = [
            'customer_id' => $customer->getKey(),
            'customer_type' => $customer->getMorphClass(),
            'subtotal' => $cart->subtotal ?? 0,
            'discount_total' => $cart->discount ?? 0,
            'shipping_total' => $cart->shipping ?? 0,
            'tax_total' => $cart->tax ?? 0,
            'grand_total' => $cart->total ?? 0,
            'currency' => $cart->currency ?? config('orders.currency.default', 'MYR'),
            'metadata' => [
                'cart_id' => $cart->id ?? null,
                'session_id' => session()->getId(),
            ],
        ];

        $items = [];
        foreach ($cart->items ?? [] as $cartItem) {
            $items[] = [
                'purchasable_id' => $cartItem->purchasable_id ?? null,
                'purchasable_type' => $cartItem->purchasable_type ?? null,
                'name' => $cartItem->name ?? 'Unknown Item',
                'sku' => $cartItem->sku ?? null,
                'quantity' => $cartItem->quantity ?? 1,
                'unit_price' => $cartItem->price ?? 0,
                'discount_amount' => $cartItem->discount ?? 0,
                'tax_amount' => $cartItem->tax ?? 0,
                'options' => $cartItem->options ?? null,
                'metadata' => $cartItem->metadata ?? null,
            ];
        }

        return $this->createOrder->execute($orderData, $items, $billingAddress, $shippingAddress);
    }
}
