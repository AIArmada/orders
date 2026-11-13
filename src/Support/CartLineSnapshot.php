<?php

declare(strict_types=1);

namespace AIArmada\Orders\Support;

use AIArmada\CommerceSupport\Support\CompositeComponents;
use JsonException;

/**
 * Immutable, normalized view of one sellable cart line.
 *
 * @phpstan-import-type Component from CompositeComponents
 *
 * Composite lines carry a trusted `components` snapshot. The parent line keeps
 * the commercial identity and price; components describe the actual inventory
 * and shipment units for one package.
 */
final readonly class CartLineSnapshot
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array{length: int|null, width: int|null, height: int|null}|null  $dimensionsCm
     * @param  list<Component>  $components
     */
    public function __construct(
        public string $lineId,
        public string $name,
        public int $quantity,
        public int $unitPrice,
        public ?string $purchasableId,
        public ?string $purchasableType,
        public ?string $productId,
        public ?string $variantId,
        public ?string $sku,
        public ?string $currency,
        public bool $requiresShipping,
        public bool $isTaxable,
        public ?string $taxClass,
        public ?int $weightGrams,
        public ?array $dimensionsCm,
        public array $components,
        public array $attributes,
    ) {}

    /**
     * @param  array<string, mixed>  $item
     */
    public static function fromArray(array $item): self
    {
        $attributes = is_array($item['attributes'] ?? null) ? $item['attributes'] : [];
        $associated = is_array($item['associated_model'] ?? null) ? $item['associated_model'] : [];
        $dimensions = is_array($attributes['dimensions'] ?? null) ? $attributes['dimensions'] : [];
        $currency = self::firstString([$attributes['currency'] ?? null]);
        $productId = self::firstString([$attributes['product_id'] ?? null]);
        $variantId = self::firstString([$attributes['variant_id'] ?? null]);

        return new self(
            lineId: self::firstString([$item['id'] ?? null]) ?? '',
            name: self::firstString([$item['name'] ?? null]) ?? '',
            quantity: max(1, self::toInt($item['quantity'] ?? 1)),
            unitPrice: max(0, self::toInt($item['price'] ?? 0)),
            purchasableId: self::firstString([
                $attributes['purchasable_id'] ?? null,
                $associated['id'] ?? null,
                $productId,
                $variantId,
                $item['id'] ?? null,
            ]),
            purchasableType: self::firstString([
                $attributes['purchasable_type'] ?? null,
                $associated['class'] ?? null,
            ]),
            productId: $productId,
            variantId: $variantId,
            sku: self::firstString([$attributes['sku'] ?? null]),
            currency: $currency !== null ? mb_strtoupper($currency) : null,
            requiresShipping: self::toBool($attributes['requires_shipping'] ?? true),
            isTaxable: self::toBool($attributes['is_taxable'] ?? true),
            taxClass: self::firstString([$attributes['tax_class'] ?? null]),
            weightGrams: self::firstInt([$attributes['weight'] ?? null]),
            dimensionsCm: self::normalizeDimensions([
                $dimensions['length'] ?? null,
                $dimensions['width'] ?? null,
                $dimensions['height'] ?? null,
            ]),
            components: array_key_exists('components', $attributes)
                ? CompositeComponents::read($attributes['components'])
                : [],
            attributes: $attributes,
        );
    }

    public function hasComponents(): bool
    {
        return $this->components !== [];
    }

    /**
     * Stable content identity used to detect cart changes without treating a
     * display-name edit as a new composition.
     */
    public function contentFingerprint(): string
    {
        return $this->hash([
            'line_id' => $this->lineId,
            'purchasable_id' => $this->purchasableId,
            'purchasable_type' => $this->purchasableType,
            'product_id' => $this->productId,
            'variant_id' => $this->variantId,
            'sku' => $this->sku,
            'quantity' => $this->quantity,
            'currency' => $this->currency,
            'requires_shipping' => $this->requiresShipping,
            'is_taxable' => $this->isTaxable,
            'tax_class' => $this->taxClass,
            'weight' => $this->weightGrams,
            'dimensions' => $this->dimensionsCm,
            'components' => $this->components,
        ]);
    }

    public function priceFingerprint(): string
    {
        return $this->hash([
            'line_id' => $this->lineId,
            'purchasable_id' => $this->purchasableId,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
        ]);
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    public function orderItemMetadata(array $base = []): array
    {
        $metadata = $base;
        unset($metadata['components'], $metadata['components_fingerprint']);

        if (array_key_exists('requires_shipping', $this->attributes)) {
            $metadata['requires_shipping'] = $this->requiresShipping;
        }

        if ($this->components !== []) {
            $metadata['components'] = $this->components;
        } elseif ($this->weightGrams !== null) {
            $metadata['weight'] = $this->weightGrams;
        }

        if ($this->components === [] && $this->dimensionsCm !== null) {
            $metadata['dimensions'] = $this->dimensionsCm;
        }

        if ($this->taxClass !== null) {
            $metadata['tax_class'] = $this->taxClass;
        }

        if ($this->hasExplicitTaxability()) {
            $metadata['is_taxable'] = $this->isTaxable;
        }

        return $metadata;
    }

    /** @return list<Component> */
    public function expandedComponents(): array
    {
        return CompositeComponents::expand($this->components, $this->quantity);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array{length: int, width: int, height: int}|null
     */
    private static function normalizeDimensions(array $values): ?array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (! is_numeric($value)) {
                return null;
            }

            $normalized[] = (int) round((float) $value);
        }

        if (count($normalized) !== 3 || in_array(0, $normalized, true)) {
            return null;
        }

        /** @var array{length: int, width: int, height: int} $dimensions */
        $dimensions = [
            'length' => $normalized[0],
            'width' => $normalized[1],
            'height' => $normalized[2],
        ];

        return $dimensions;
    }

    private function hasExplicitTaxability(): bool
    {
        return array_key_exists('is_taxable', $this->attributes);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hash(array $payload): string
    {
        try {
            return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return hash('sha256', serialize($payload));
        }
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private static function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $resolved = mb_trim((string) $value);

            if ($resolved !== '') {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private static function firstInt(array $values): ?int
    {
        foreach ($values as $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $resolved = (int) round((float) $value);

            if ($resolved > 0) {
                return $resolved;
            }
        }

        return null;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return ! in_array(mb_strtolower(mb_trim($value)), ['', '0', 'false', 'no', 'off'], true);
        }

        return (bool) $value;
    }
}
