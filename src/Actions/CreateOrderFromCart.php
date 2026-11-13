<?php

declare(strict_types=1);

namespace AIArmada\Orders\Actions;

use AIArmada\Cart\Cart;
use AIArmada\Cart\Contracts\BuyablePriceResolver;
use AIArmada\Cart\Contracts\CartManagerInterface;
use AIArmada\CommerceSupport\Contracts\Buyable;
use AIArmada\CommerceSupport\Support\CompositeComponents;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerScopeConfig;
use AIArmada\CommerceSupport\Support\OwnerWriteGuard;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Support\CartLineSnapshot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
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
            $line = CartLineSnapshot::fromArray($cartItem->toArray());
            if ($line->hasComponents()) {
                $this->assertCurrentPackage($cart, $line);
            }

            $itemSubtotal = $cartItem->getRawSubtotal();
            $metadata = $cartItem->getAttribute('metadata');
            $metadata = is_array($metadata) ? $metadata : $cartItem->getLineItemMetadata();

            $items[] = [
                'purchasable_id' => $line->purchasableId ?? $cartItem->getLineItemId(),
                'purchasable_type' => $line->purchasableType,
                'name' => $cartItem->getLineItemName(),
                'sku' => $line->sku ?? $cartItem->getLineItemSku(),
                'quantity' => $cartItem->getLineItemQuantity(),
                'unit_price' => (int) $cartItem->getLineItemPrice()->getAmount(),
                'discount_amount' => (int) $cartItem->getLineItemDiscount()->getAmount(),
                'tax_amount' => $cartItem->getConditions()->byType('tax')->getTotalCharges($itemSubtotal),
                'currency' => $line->currency,
                'options' => $cartItem->getAttribute('options'),
                'metadata' => $line->orderItemMetadata($metadata),
            ];
        }

        return $this->createOrder->execute($orderData, $items, $billingAddress, $shippingAddress, $intakeSource, $intakeId);
    }

    private function assertCurrentPackage(Cart $cart, CartLineSnapshot $line): void
    {
        $type = $line->purchasableType ?? '';
        $class = Relation::getMorphedModel($type) ?? $type;
        $package = is_a($class, Model::class, true) && is_a($class, Buyable::class, true)
            ? $class::query()->find($line->purchasableId)
            : null;

        if (! $package instanceof Buyable || ! $package->canBePurchased($line->quantity)) {
            throw new InvalidArgumentException('The composite package is unavailable. Review the cart before creating the order.');
        }

        $attributes = $package->getBuyableAttributes();
        $components = CompositeComponents::read($attributes['components'] ?? null);
        if ($components !== $line->components || $package->getBuyableCurrency() !== $line->currency
            || (bool) ($attributes['is_taxable'] ?? true) !== $line->isTaxable
            || (string) ($attributes['tax_class'] ?? '') !== (string) $line->taxClass) {
            throw new InvalidArgumentException('The composite package has changed. Review the cart before creating the order.');
        }

        $context = ['currency' => $line->currency];
        foreach (['customer_id', 'customer_type'] as $key) {
            $value = $cart->getMetadata($key);
            if (is_string($value) || is_int($value)) {
                $context[$key] = $value;
            }
        }

        $price = app()->bound(BuyablePriceResolver::class)
            ? app(BuyablePriceResolver::class)->resolve($package, $line->quantity, $context)->unitPrice
            : $package->getBuyablePrice();

        if ($price !== $line->unitPrice) {
            throw new InvalidArgumentException('The composite package price has changed. Review the cart before creating the order.');
        }
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
