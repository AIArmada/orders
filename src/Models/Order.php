<?php

declare(strict_types=1);

namespace AIArmada\Orders\Models;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Traits\HasAddresses;
use AIArmada\CommerceSupport\Concerns\HasCommerceAudit;
use AIArmada\CommerceSupport\Concerns\LogsCommerceActivity;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Traits\FormatsMoney;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Orders\Database\Factories\OrderFactory;
use AIArmada\Orders\States\OrderStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use OwenIt\Auditing\Contracts\Auditable;
use Spatie\ModelStates\HasStates;

/**
 * @property string $id
 * @property string $order_number
 * @property string|null $intake_source
 * @property string|null $intake_id
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
 * @property int $paid_total
 * @property int $refunded_total
 * @property int $pending_refunded_total
 * @property string $currency
 * @property string|null $notes
 * @property string|null $internal_notes
 * @property array|null $metadata
 * @property CarbonInterface|null $paid_at
 * @property CarbonInterface|null $shipped_at
 * @property CarbonInterface|null $delivered_at
 * @property CarbonInterface|null $canceled_at
 * @property CarbonInterface|null $payment_failed_at
 * @property CarbonInterface|null $held_at
 * @property CarbonInterface|null $flagged_at
 * @property CarbonInterface|null $returned_at
 * @property CarbonInterface|null $refunded_at
 * @property CarbonInterface|null $completed_at
 * @property string|null $cancellation_reason
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Collection<int, OrderItem> $items
 * @property-read Collection<int, Address> $addresses
 * @property-read Collection<int, OrderPayment> $payments
 * @property-read Collection<int, OrderRefund> $refunds
 * @property-read Collection<int, OrderNote> $orderNotes
 */
class Order extends Model implements Auditable
{
    use FormatsMoney;
    use HasAddresses;
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
    use LogsCommerceActivity;
    use Notifiable;

    private bool $allowUnsafeDelete = false;

    protected static string $ownerScopeConfigKey = 'orders.owner';

    public const ADDRESS_CONTACT_METADATA_KEY = 'order_contact';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'owner_type',
        'owner_id',
        'order_number',
        'intake_source',
        'intake_id',
        'status',
        'customer_id',
        'customer_type',
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
        'payment_failed_at',
        'held_at',
        'flagged_at',
        'returned_at',
        'refunded_at',
        'completed_at',
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
        'paid_total' => 0,
        'refunded_total' => 0,
        'pending_refunded_total' => 0,
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
            $parts[] = CarbonImmutable::now()->format($dateFormat);
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

    /**
     * Whether the order is currently on hold.
     *
     * held_at is a toggle timestamp: set when the hold starts and cleared
     * when the hold is released, so null always means "not on hold".
     */
    public function isOnHold(): bool
    {
        return $this->held_at !== null;
    }

    public function isFlaggedAsFraud(): bool
    {
        return $this->flagged_at !== null;
    }

    public function isReturned(): bool
    {
        return $this->returned_at !== null;
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

    /**
     * Delete an order and its owned records atomically.
     *
     * Paid or final orders must be cancelled/refunded instead of deleted. A
     * force delete is reserved for explicit retention or administrative work.
     */
    public function delete(bool $force = false): ?bool
    {
        $previous = $this->allowUnsafeDelete;
        $this->allowUnsafeDelete = $force;

        try {
            return DB::transaction(fn (): ?bool => parent::delete());
        } finally {
            $this->allowUnsafeDelete = $previous;
        }
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
        return (int) $this->paid_total;
    }

    public function getTotalRefunded(): int
    {
        return (int) $this->refunded_total;
    }

    public function getTotalPendingRefunded(): int
    {
        return (int) $this->pending_refunded_total;
    }

    public function getRemainingRefundable(): int
    {
        $totalPaid = $this->getTotalPaid();
        $refundCeiling = $totalPaid > 0
            ? $totalPaid
            : (int) $this->grand_total;

        return max(0, $refundCeiling - $this->getTotalRefunded() - $this->getTotalPendingRefunded());
    }

    public function getBalanceDue(): int
    {
        return max(0, $this->grand_total - $this->getTotalPaid());
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
        $subtotal = (int) $this->items()->sum(DB::raw('quantity * unit_price'));
        $taxTotal = (int) $this->items()->sum('tax_amount');

        $this->subtotal = $subtotal;
        $this->tax_total = $taxTotal;
        $this->grand_total = $subtotal + $taxTotal + $this->shipping_total - $this->discount_total;

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
            'paid_total',
            'refunded_total',
            'pending_refunded_total',
            'paid_at',
            'shipped_at',
            'delivered_at',
            'canceled_at',
            'payment_failed_at',
            'refunded_at',
            'completed_at',
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

        });

        static::deleting(function (Order $order): void {
            if (! $order->allowUnsafeDelete && ($order->isPaid() || $order->isFinal())) {
                throw new LogicException('Paid or final orders must be cancelled or refunded instead of deleted. Pass force=true only for an explicit administrative retention override.');
            }

            $order->items()->delete();
            $order->addresses()->detach();
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
            'paid_total' => 'integer',
            'refunded_total' => 'integer',
            'pending_refunded_total' => 'integer',
            'metadata' => 'array',
            'paid_at' => 'immutable_datetime',
            'shipped_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
            'payment_failed_at' => 'immutable_datetime',
            'held_at' => 'immutable_datetime',
            'flagged_at' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
            'refunded_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return array<string, string>|string|null
     */
    public function routeNotificationForMail(Notification $notification): array | string | null
    {
        $address = $this->primaryAddress('billing') ?? $this->primaryAddress('shipping');

        if ($address === null) {
            return null;
        }

        $metadata = $address->metadata;
        $contact = is_array($metadata)
            && is_array($metadata[static::ADDRESS_CONTACT_METADATA_KEY] ?? null)
            ? $metadata[static::ADDRESS_CONTACT_METADATA_KEY]
            : [];
        $email = $contact['email'] ?? null;

        if (! is_string($email) || $email === '') {
            return null;
        }

        $name = mb_trim(implode(' ', array_filter([
            $contact['first_name'] ?? null,
            $contact['last_name'] ?? null,
        ], static fn (mixed $value): bool => is_string($value) && $value !== '')));

        return $name !== '' ? [$email => $name] : $email;
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
