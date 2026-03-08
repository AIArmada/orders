<?php

declare(strict_types=1);

namespace AIArmada\Orders\Models;

use AIArmada\CommerceSupport\Concerns\HasCommerceAudit;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Traits\FormatsMoney;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Orders\Database\Factories\OrderFactory;
use AIArmada\Orders\Enums\PaymentStatus;
use AIArmada\Orders\States\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\ModelStates\HasStates;

/**
 * @property string $id
 * @property string $order_number
 * @property OrderStatus $status
 * @property string|null $customer_id
 * @property string|null $customer_type
 * @property string|null $owner_id
 * @property string|null $owner_type
 * @property int $subtotal
 * @property int $discount_total
 * @property int $shipping_total
 * @property int $tax_total
 * @property int $grand_total
 * @property string $currency
 * @property string|null $notes
 * @property string|null $internal_notes
 * @property array|null $metadata
 * @property \Carbon\CarbonInterface|null $paid_at
 * @property \Carbon\CarbonInterface|null $shipped_at
 * @property \Carbon\CarbonInterface|null $delivered_at
 * @property \Carbon\CarbonInterface|null $canceled_at
 * @property string|null $cancellation_reason
 * @property \Carbon\CarbonInterface $created_at
 * @property \Carbon\CarbonInterface $updated_at
 * @property-read Collection<int, OrderItem> $items
 * @property-read OrderAddress|null $billingAddress
 * @property-read OrderAddress|null $shippingAddress
 * @property-read Collection<int, OrderPayment> $payments
 * @property-read Collection<int, OrderRefund> $refunds
 * @property-read Collection<int, OrderNote> $orderNotes
 */
class Order extends Model implements Auditable
{
    use FormatsMoney;
    use HasCommerceAudit {
        getAuditThreshold as protected getAuditThresholdFromTrait;
        readyForAuditing as protected readyForAuditingFromTrait;
    }

    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use HasOwner {
        scopeForOwner as baseScopeForOwner;
    }
    use HasOwnerScopeConfig;
    use HasStates;
    use HasUuids;

    protected static string $ownerScopeConfigKey = 'orders.owner';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'order_number',
        'status',
        'customer_id',
        'customer_type',
        'owner_id',
        'owner_type',
        'subtotal',
        'discount_total',
        'shipping_total',
        'tax_total',
        'grand_total',
        'currency',
        'notes',
        'internal_notes',
        'metadata',
        'paid_at',
        'shipped_at',
        'delivered_at',
        'canceled_at',
        'cancellation_reason',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'subtotal' => 0,
        'discount_total' => 0,
        'shipping_total' => 0,
        'tax_total' => 0,
        'grand_total' => 0,
        'currency' => 'MYR',
    ];

    /**
     * Generate a unique order number.
     */
    public static function generateOrderNumber(): string
    {
        $config = config('orders.order_number');
        $prefix = $config['prefix'] ?? 'ORD';
        $separator = $config['separator'] ?? '-';
        $length = $config['length'] ?? 8;
        $useDate = $config['use_date'] ?? true;
        $dateFormat = $config['date_format'] ?? 'Ymd';

        $parts = [$prefix];

        if ($useDate) {
            $parts[] = now()->format($dateFormat);
        }

        $parts[] = mb_strtoupper(Str::random($length));

        return implode($separator, $parts);
    }

    public function getTable(): string
    {
        return config('orders.database.tables.orders', 'orders');
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
     * @return HasMany<OrderItem, Order>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasOne<OrderAddress, Order>
     */
    public function billingAddress(): HasOne
    {
        return $this->hasOne(OrderAddress::class)->where('type', 'billing');
    }

    /**
     * @return HasOne<OrderAddress, Order>
     */
    public function shippingAddress(): HasOne
    {
        return $this->hasOne(OrderAddress::class)->where('type', 'shipping');
    }

    /**
     * @return HasMany<OrderAddress, Order>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(OrderAddress::class);
    }

    /**
     * @return HasMany<OrderPayment, Order>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
    }

    /**
     * @return HasMany<OrderRefund, Order>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(OrderRefund::class);
    }

    /**
     * @return HasMany<OrderNote, Order>
     */
    public function orderNotes(): HasMany
    {
        return $this->hasMany(OrderNote::class)->orderBy('created_at', 'desc');
    }

    /**
     * Polymorphic relationship to the customer (User, Customer model, etc.)
     *
     * @return MorphTo<Model, $this>
     */
    public function customer(): MorphTo
    {
        return $this->morphTo();
    }

    // ─────────────────────────────────────────────────────────────
    // STATUS HELPERS
    // ─────────────────────────────────────────────────────────────

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    public function isShipped(): bool
    {
        return $this->shipped_at !== null;
    }

    public function isDelivered(): bool
    {
        return $this->delivered_at !== null;
    }

    public function isCanceled(): bool
    {
        return $this->canceled_at !== null;
    }

    public function canBeCanceled(): bool
    {
        return $this->status->canCancel();
    }

    public function canBeRefunded(): bool
    {
        return $this->status->canRefund();
    }

    public function canBeModified(): bool
    {
        return $this->status->canModify();
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    // ─────────────────────────────────────────────────────────────
    // MONEY ACCESSORS
    // ─────────────────────────────────────────────────────────────

    public function getFormattedSubtotal(): string
    {
        return $this->formatMoney($this->subtotal);
    }

    public function getFormattedDiscountTotal(): string
    {
        return $this->formatMoney($this->discount_total);
    }

    public function getFormattedShippingTotal(): string
    {
        return $this->formatMoney($this->shipping_total);
    }

    public function getFormattedTaxTotal(): string
    {
        return $this->formatMoney($this->tax_total);
    }

    public function getFormattedGrandTotal(): string
    {
        return $this->formatMoney($this->grand_total);
    }

    // ─────────────────────────────────────────────────────────────
    // PAYMENT HELPERS
    // ─────────────────────────────────────────────────────────────

    public function getTotalPaid(): int
    {
        return $this->payments()
            ->where('status', PaymentStatus::Completed)
            ->sum('amount');
    }

    public function getTotalRefunded(): int
    {
        return $this->refunds()
            ->where('status', PaymentStatus::Completed)
            ->sum('amount');
    }

    public function getBalanceDue(): int
    {
        return max(0, $this->grand_total - $this->getTotalPaid() + $this->getTotalRefunded());
    }

    public function isFullyPaid(): bool
    {
        return $this->getBalanceDue() === 0;
    }

    // ─────────────────────────────────────────────────────────────
    // ITEM HELPERS
    // ─────────────────────────────────────────────────────────────

    public function getItemCount(): int
    {
        return $this->items()->sum('quantity');
    }

    public function recalculateTotals(): self
    {
        $itemsTotal = (int) $this->items()->sum('total');
        $taxTotal = (int) $this->items()->sum('tax_amount');

        $this->subtotal = $itemsTotal;
        $this->tax_total = $taxTotal;
        $this->grand_total = $itemsTotal + $this->shipping_total - $this->discount_total;

        return $this;
    }

    public function getAuditThreshold(): int
    {
        return (int) config('orders.audit.threshold', $this->getAuditThresholdFromTrait());
    }

    public function readyForAuditing(): bool
    {
        if (! (bool) config('orders.audit.enabled', true)) {
            return false;
        }

        return $this->readyForAuditingFromTrait();
    }

    /**
     * Get the attributes that should be audited for compliance.
     *
     * @return array<int, string>
     */
    public function getAuditInclude(): array
    {
        return [
            'status',
            'subtotal',
            'discount_total',
            'shipping_total',
            'tax_total',
            'grand_total',
            'paid_at',
            'shipped_at',
            'delivered_at',
            'canceled_at',
            'cancellation_reason',
        ];
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }

    // ─────────────────────────────────────────────────────────────
    // BOOT
    // ─────────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            if (empty($order->order_number)) {
                $order->order_number = static::generateOrderNumber();
            }

            if (! (bool) config('orders.owner.enabled', true)) {
                return;
            }

            $hasOwnerType = $order->owner_type !== null;
            $hasOwnerId = $order->owner_id !== null;

            if ($hasOwnerType xor $hasOwnerId) {
                throw new InvalidArgumentException('owner_type and owner_id must both be set or both be null.');
            }

            $ownerFromContext = OwnerContext::resolve();

            if ($ownerFromContext !== null && $hasOwnerType && $hasOwnerId) {
                if (
                    $order->owner_type !== $ownerFromContext->getMorphClass()
                    || (string) $order->owner_id !== (string) $ownerFromContext->getKey()
                ) {
                    throw new InvalidArgumentException('Explicit owner does not match the current owner context.');
                }

                return;
            }

            if ($hasOwnerType && $hasOwnerId) {
                return;
            }

            if (! (bool) config('orders.owner.auto_assign_on_create', true)) {
                return;
            }

            if ($ownerFromContext === null) {
                return;
            }

            $order->assignOwner($ownerFromContext);
        });

        static::deleting(function (Order $order): void {
            $order->items()->delete();
            $order->addresses()->delete();
            $order->payments()->delete();
            $order->refunds()->delete();
            $order->orderNotes()->delete();
        });
    }

    // ─────────────────────────────────────────────────────────────
    // CASTS
    // ─────────────────────────────────────────────────────────────

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal' => 'integer',
            'discount_total' => 'integer',
            'shipping_total' => 'integer',
            'tax_total' => 'integer',
            'grand_total' => 'integer',
            'metadata' => 'array',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    /**
     * Get tags for categorizing this audit.
     *
     * @return array<int, string>
     */
    protected function getAuditTags(): array
    {
        return ['commerce', 'orders'];
    }
}
