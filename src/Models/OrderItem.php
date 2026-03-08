<?php

declare(strict_types=1);

namespace AIArmada\Orders\Models;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerWriteGuard;
use AIArmada\CommerceSupport\Traits\FormatsMoney;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * @property string $id
 * @property string $order_id
 * @property string|null $purchasable_id
 * @property string|null $purchasable_type
 * @property string $name
 * @property string|null $sku
 * @property string|null $owner_id
 * @property string|null $owner_type
 * @property int $quantity
 * @property int $unit_price
 * @property int $discount_amount
 * @property int $tax_amount
 * @property int $total
 * @property string $currency
 * @property array|null $options
 * @property array|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Order $order
 */
class OrderItem extends Model
{
    use FormatsMoney;
    use HasOwner {
        scopeForOwner as baseScopeForOwner;
    }
    use HasOwnerScopeConfig;
    use HasUuids;

    protected static string $ownerScopeConfigKey = 'orders.owner';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'order_id',
        'owner_id',
        'owner_type',
        'purchasable_id',
        'purchasable_type',
        'name',
        'sku',
        'quantity',
        'unit_price',
        'discount_amount',
        'tax_amount',
        'total',
        'currency',
        'options',
        'metadata',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quantity' => 1,
        'unit_price' => 0,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total' => 0,
        'currency' => 'MYR',
    ];

    public function getTable(): string
    {
        return config('orders.database.tables.order_items', 'order_items');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForOwner(Builder $query, Model | string | null $owner = OwnerContext::CURRENT, bool $includeGlobal = false): Builder
    {
        /** @var Builder<static> $scoped */
        $scoped = $this->baseScopeForOwner($query, $owner, $includeGlobal);

        return $scoped;
    }

    // ─────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<Order, OrderItem>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Polymorphic relationship to the purchasable (Product, Variant, etc.)
     *
     * @return MorphTo<Model, $this>
     */
    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }

    // ─────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────

    /**
     * Calculate the line total based on quantity, price, discount, and tax.
     */
    public function calculateTotal(): int
    {
        $subtotal = $this->quantity * $this->unit_price;
        $afterDiscount = $subtotal - $this->discount_amount;

        return $afterDiscount + $this->tax_amount;
    }

    /**
     * Get the formatted unit price.
     */
    public function getFormattedUnitPrice(): string
    {
        return $this->formatMoney($this->unit_price);
    }

    /**
     * Get the formatted total.
     */
    public function getFormattedTotal(): string
    {
        return $this->formatMoney($this->total);
    }

    // ─────────────────────────────────────────────────────────────
    // BOOT
    // ─────────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (OrderItem $item): void {
            if (! (bool) config('orders.owner.enabled', true)) {
                return;
            }

            if (blank($item->order_id)) {
                throw new InvalidArgumentException('order_id is required.');
            }

            $owner = OwnerContext::resolve();
            $includeGlobal = (bool) config('orders.owner.include_global', false);
            $order = OwnerWriteGuard::findOrFailForOwner(Order::class, $item->order_id, $owner, $includeGlobal);

            if ($order->owner_type !== null && $order->owner_id !== null) {
                $item->owner_type = $order->owner_type;
                $item->owner_id = $order->owner_id;

                return;
            }

            $item->owner_type = null;
            $item->owner_id = null;
        });

        static::saving(function (OrderItem $item): void {
            $item->total = $item->calculateTotal();
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'discount_amount' => 'integer',
            'tax_amount' => 'integer',
            'total' => 'integer',
            'options' => 'array',
            'metadata' => 'array',
        ];
    }
}
