<?php

declare(strict_types=1);

namespace AIArmada\Orders\Database\Factories;

use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = $this->faker->numberBetween(1, 5);
        $unitPrice = $this->faker->numberBetween(1000, 20000);
        $discountAmount = $this->faker->optional(0.2)->numberBetween(0, $unitPrice / 10) ?? 0;
        $subtotal = ($unitPrice * $quantity) - $discountAmount;
        $taxAmount = (int) ($subtotal * 0.06);
        $total = $subtotal + $taxAmount;

        return [
            'order_id' => Order::factory(),
            'name' => $this->faker->words(3, true),
            'sku' => mb_strtoupper(Str::random(8)),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount_amount' => $discountAmount,
            'tax_rate' => 600, // 6% stored as integer (600 = 6.00%)
            'tax_amount' => $taxAmount,
            'subtotal' => $subtotal,
            'total' => $total,
            'metadata' => null,
        ];
    }

    /**
     * Item with specific quantity.
     */
    public function quantity(int $quantity): static
    {
        return $this->state(function (array $attributes) use ($quantity) {
            $unitPrice = $attributes['unit_price'];
            $discountAmount = $attributes['discount_amount'] ?? 0;
            $subtotal = ($unitPrice * $quantity) - $discountAmount;
            $taxAmount = (int) ($subtotal * 0.06);
            $total = $subtotal + $taxAmount;

            return [
                'quantity' => $quantity,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total' => $total,
            ];
        });
    }

    /**
     * Item with specific price.
     */
    public function priced(int $unitPriceInCents): static
    {
        return $this->state(function (array $attributes) use ($unitPriceInCents) {
            $quantity = $attributes['quantity'];
            $discountAmount = $attributes['discount_amount'] ?? 0;
            $subtotal = ($unitPriceInCents * $quantity) - $discountAmount;
            $taxAmount = (int) ($subtotal * 0.06);
            $total = $subtotal + $taxAmount;

            return [
                'unit_price' => $unitPriceInCents,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total' => $total,
            ];
        });
    }

    /**
     * Digital item (no shipping).
     */
    public function digital(): static
    {
        return $this->state(fn (array $attributes) => [
            'requires_shipping' => false,
            'is_digital' => true,
        ]);
    }
}
