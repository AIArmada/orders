<?php

declare(strict_types=1);

namespace AIArmada\Orders\Support;

use AIArmada\CommerceSupport\Support\CompositeComponents;
use AIArmada\Orders\Models\OrderItem;
use InvalidArgumentException;

/**
 * @phpstan-import-type Component from CompositeComponents
 */
final readonly class PackageUnit
{
    /** @var list<Component> */
    public array $components;

    public function __construct(public OrderItem $item, public int $ordinal)
    {
        $this->components = self::componentsOf($item);

        if ($this->components === [] || $ordinal < 1 || $ordinal > $item->quantity) {
            throw new InvalidArgumentException('Invalid package unit for this order item.');
        }
    }

    /** @return list<Component> */
    public static function componentsOf(OrderItem $item): array
    {
        return array_key_exists('components', $item->metadata ?? [])
            ? CompositeComponents::read($item->metadata['components'])
            : [];
    }

    public function refundAmount(): int
    {
        $goods = $this->item->calculateTotal() - $this->item->tax_amount;

        return $this->share($goods) + $this->share($this->item->tax_amount);
    }

    private function share(int $amount): int
    {
        $quantity = $this->item->quantity;

        return intdiv($amount, $quantity) + (int) ($this->ordinal <= $amount % $quantity);
    }
}
