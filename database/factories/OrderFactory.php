<?php

declare(strict_types=1);

namespace AIArmada\Orders\Database\Factories;

use AIArmada\Orders\Models\Order;
use AIArmada\Orders\States\Created;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = $this->faker->numberBetween(1000, 100000);
        $shippingTotal = $this->faker->numberBetween(500, 2000);
        $discountTotal = $this->faker->numberBetween(0, $subtotal / 10);
        $taxTotal = (int) (($subtotal - $discountTotal) * 0.06);
        $grandTotal = $subtotal + $shippingTotal + $taxTotal - $discountTotal;

        return [
            'order_number' => 'ORD-' . now()->format('Ymd') . '-' . mb_strtoupper(Str::random(8)),
            'status' => Created::class,
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'shipping_total' => $shippingTotal,
            'tax_total' => $taxTotal,
            'grand_total' => $grandTotal,
            'currency' => 'MYR',
            'notes' => $this->faker->optional()->sentence(),
        ];
    }

    /**
     * Order in created state.
     */
    public function created(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Created::class,
        ]);
    }

    /**
     * Order that has been paid.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => \AIArmada\Orders\States\Processing::class,
            'paid_at' => now(),
        ]);
    }

    /**
     * Order that has been shipped.
     */
    public function shipped(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => \AIArmada\Orders\States\Shipped::class,
            'paid_at' => now()->subDays(2),
            'shipped_at' => now(),
        ]);
    }

    /**
     * Order that has been delivered.
     */
    public function delivered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => \AIArmada\Orders\States\Delivered::class,
            'paid_at' => now()->subDays(5),
            'shipped_at' => now()->subDays(3),
            'delivered_at' => now(),
        ]);
    }

    /**
     * Order that has been canceled.
     */
    public function canceled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => \AIArmada\Orders\States\Canceled::class,
            'canceled_at' => now(),
            'cancellation_reason' => $this->faker->sentence(),
        ]);
    }

    /**
     * High value order.
     */
    public function highValue(): static
    {
        return $this->state(function (array $attributes) {
            $subtotal = $this->faker->numberBetween(50000, 500000);
            $shippingTotal = 0; // Free shipping for high value
            $discountTotal = (int) ($subtotal * 0.10); // 10% discount
            $taxTotal = (int) (($subtotal - $discountTotal) * 0.06);
            $grandTotal = $subtotal + $shippingTotal + $taxTotal - $discountTotal;

            return [
                'subtotal' => $subtotal,
                'discount_total' => $discountTotal,
                'shipping_total' => $shippingTotal,
                'tax_total' => $taxTotal,
                'grand_total' => $grandTotal,
            ];
        });
    }
}
