<?php

declare(strict_types=1);

namespace AIArmada\Orders\Models;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerWriteGuard;
use AIArmada\CommerceSupport\Traits\FormatsMoney;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Orders\Enums\PaymentStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * @property string $id
 * @property string $order_id
 * @property string $gateway
 * @property string|null $transaction_id
 * @property string|null $owner_id
 * @property string|null $owner_type
 * @property int $amount
 * @property string $currency
 * @property PaymentStatus $status
 * @property string|null $failure_reason
 * @property array|null $metadata
 * @property CarbonInterface|null $paid_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Order $order
 */
class OrderPayment extends Model
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
        'gateway',
        'transaction_id',
        'amount',
        'currency',
        'status',
        'failure_reason',
        'metadata',
        'paid_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => PaymentStatus::Pending,
        'currency' => 'MYR',
    ];

    public function getTable(): string
    {
        return config('orders.database.tables.order_payments', 'order_payments');
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
     * @return BelongsTo<Order, OrderPayment>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    // ─────────────────────────────────────────────────────────────
    // STATUS HELPERS
    // ─────────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }

    public function isCompleted(): bool
    {
        return $this->status === PaymentStatus::Completed;
    }

    public function isFailed(): bool
    {
        return $this->status === PaymentStatus::Failed;
    }

    public function isRefunded(): bool
    {
        return $this->status === PaymentStatus::Refunded;
    }

    public function markAsCompleted(?string $transactionId = null): self
    {
        $this->status = PaymentStatus::Completed;
        $this->paid_at = now();

        if ($transactionId !== null) {
            $this->transaction_id = $transactionId;
        }

        $this->save();

        return $this;
    }

    public function markAsFailed(string $reason): self
    {
        $this->status = PaymentStatus::Failed;
        $this->failure_reason = $reason;
        $this->save();

        return $this;
    }

    // ─────────────────────────────────────────────────────────────
    // MONEY HELPERS
    // ─────────────────────────────────────────────────────────────

    public function getFormattedAmount(): string
    {
        return $this->formatMoney($this->amount);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => PaymentStatus::class,
            'metadata' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OrderPayment $payment): void {
            if (! (bool) config('orders.owner.enabled', true)) {
                return;
            }

            if (blank($payment->order_id)) {
                throw new InvalidArgumentException('order_id is required.');
            }

            $owner = OwnerContext::resolve();
            $includeGlobal = (bool) config('orders.owner.include_global', false);
            $order = OwnerWriteGuard::findOrFailForOwner(Order::class, $payment->order_id, $owner, $includeGlobal);

            if ($order->owner_type !== null && $order->owner_id !== null) {
                $payment->owner_type = $order->owner_type;
                $payment->owner_id = $order->owner_id;
            } else {
                $payment->owner_type = null;
                $payment->owner_id = null;
            }
        });
    }
}
