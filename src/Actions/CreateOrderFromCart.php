<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\Cart\Cart;
use AIArmada\Cart\Contracts\CartManagerInterface;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScopeConfig;
use AIArmada\CommerceSupport\Support\OwnerWriteGuard;
use AIArmada\Orders\Models\Order;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class CreateOrderFromCart
{
    public function __construct(
        private readonly CreateOrder $createOrder,
    ) {}

    /**
     * @param  array<string, mixed>|null  $billingAddress
     * @param  array<string, mixed>|null  $shippingAddress
     * @param  string|null  $intakeSource  Deduplication identity source
     * @param  string|null  $intakeId  Deduplication identity
     * @param  string|null  $sessionId  Checkout/session identifier for order metadata
     */
    public function execute(
        Cart | CartManagerInterface $cart,
        Model $customer,
        ?array $billingAddress = null,
        ?array $shippingAddress = null,
        ?string $intakeSource = null,
        ?string $intakeId = null,
        ?string $sessionId = null,
    ): Order {
        $cart = $cart instanceof CartManagerInterface ? $cart->getCurrentCart() : $cart;
        $customer = $this->resolveCustomer($customer);
        $subtotal = $cart->getRawSubtotal();

        $orderData = [
            'customer_id' => $customer->getKey(),
            'customer_type' => $customer->getMorphClass(),
            'subtotal' => $subtotal,
            'discount_total' => $cart->getItems()->getTotalDiscount()
                + $cart->getConditionsByType('discount')->getTotalDiscount($subtotal),
            'shipping_total' => $cart->getConditionsByType('shipping')->getTotalCharges($subtotal),
            'tax_total' => $cart->getConditionsByType('tax')->getTotalCharges($subtotal),
            'grand_total' => $cart->getRawTotal(),
            'currency' => $cart->getMetadata('currency', config('orders.currency.default', 'MYR')),
            'metadata' => [
                'cart_id' => $cart->getId(),
                'session_id' => $sessionId,
            ],
        ];

        $items = [];
        foreach ($cart->getItems() as $cartItem) {
            $associatedModel = $cartItem->getAssociatedModel();
            $purchasableId = $cartItem->getAttribute('purchasable_id');
            $purchasableType = $cartItem->getAttribute('purchasable_type');

            if ($associatedModel instanceof Model) {
                $purchasableId = $associatedModel->getKey();
                $purchasableType = $associatedModel->getMorphClass();
            }

            if ($purchasableId === null) {
                $purchasableId = $cartItem->getLineItemId();
            }

            $itemSubtotal = $cartItem->getRawSubtotal();
            $items[] = [
                'purchasable_id' => $purchasableId,
                'purchasable_type' => $purchasableType,
                'name' => $cartItem->getLineItemName(),
                'sku' => $cartItem->getLineItemSku(),
                'quantity' => $cartItem->getLineItemQuantity(),
                'unit_price' => (int) $cartItem->getLineItemPrice()->getAmount(),
                'discount_amount' => (int) $cartItem->getLineItemDiscount()->getAmount(),
                'tax_amount' => $cartItem->getConditions()->byType('tax')->getTotalCharges($itemSubtotal),
                'options' => $cartItem->getAttribute('options'),
                'metadata' => $cartItem->getAttribute('metadata', $cartItem->getLineItemMetadata()),
            ];
        }

        return $this->createOrder->execute($orderData, $items, $billingAddress, $shippingAddress, $intakeSource, $intakeId);
    }

    private function resolveCustomer(Model $customer): Model
    {
        if (! method_exists($customer, 'ownerScopeConfig')) {
            return $customer;
        }

        $ownerScopeConfig = call_user_func([$customer::class, 'ownerScopeConfig']);

        if (! $ownerScopeConfig instanceof OwnerScopeConfig || ! $ownerScopeConfig->enabled) {
            return $customer;
        }

        $customerId = $customer->getKey();

        if (! is_int($customerId) && ! is_string($customerId)) {
            throw new InvalidArgumentException('A customer with a scalar key is required.');
        }

        return OwnerWriteGuard::findOrFailForOwner(
            $customer::class,
            $customerId,
            OwnerContext::CURRENT,
            (bool) config('customers.features.owner.include_global', false),
        );
    }
}
