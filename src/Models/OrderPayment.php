<?php

declare(strict_types=1);

namespace AIArmada\Orders\Models;

use AIArmada\CommerceSupport\Concerns\HasCommerceAudit;
use AIArmada\CommerceSupport\Concerns\LogsCommerceActivity;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerWriteGuard;
use AIArmada\CommerceSupport\Traits\FormatsMoney;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Orders\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use OwenIt\Auditing\Contracts\Auditable;

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
 * @property CarbonInterface|null $failed_at
 * @property CarbonInterface|null $refunded_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Order $order
 */
class OrderPayment extends Model implements Auditable
{
    use FormatsMoney;
    use HasCommerceAudit;
    use HasOwner {
        scopeForOwner as baseScopeForOwner;
    }
    use HasOwnerScopeConfig;
    use HasUuids;
    use LogsCommerceActivity;

    protected static string $ownerScopeConfigKey = 'orders.owner';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'order_id',
        'gateway',
        'transaction_id',
        'amount',
        'currency',
        'status',
        'failure_reason',
        'metadata',
        'paid_at',
        'failed_at',
        'refunded_at',
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
        $this->paid_at = CarbonImmutable::now();

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
        $this->failed_at = CarbonImmutable::now();
        $this->save();

        return $this;
    }

    public function markAsRefunded(): self
    {
        $this->status = PaymentStatus::Refunded;
        $this->refunded_at = CarbonImmutable::now();
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
            'paid_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'refunded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (OrderPayment $payment): void {
            $payment->assertTransactionIdentityIsAvailable();
        });

        static::creating(function (OrderPayment $payment): void {
            if (! (bool) config('orders.owner.enabled', false)) {
                return;
            }

            if (blank($payment->order_id)) {
                throw new InvalidArgumentException('order_id is required.');
            }

            if (! Order::ownerScopeConfig()->enabled) {
                $order = Order::query()->findOrFail($payment->order_id);
            } else {
                $owner = OwnerContext::resolve();
                $includeGlobal = (bool) config('orders.owner.include_global', false);
                $order = OwnerWriteGuard::findOrFailForOwner(Order::class, $payment->order_id, $owner, $includeGlobal);
            }

            if ($order->owner_type !== null && $order->owner_id !== null) {
                $payment->owner_type = $order->owner_type;
                $payment->owner_id = $order->owner_id;
            } else {
                $payment->owner_type = null;
                $payment->owner_id = null;
            }
        });

        // Keep the parent order's cached paid_total in sync for every write
        // path (transitions, actions, and direct creates alike) using atomic
        // increments. paid_total is cumulative: only entering Completed adds.
        static::created(function (OrderPayment $payment): void {
            if ($payment->status === PaymentStatus::Completed && (int) $payment->amount > 0) {
                Order::query()->whereKey($payment->order_id)->increment('paid_total', (int) $payment->amount);
            }
        });

        static::updated(function (OrderPayment $payment): void {
            $wasCompleted = self::paymentStatusWas($payment, PaymentStatus::Completed);
            $isCompleted = $payment->status === PaymentStatus::Completed;
            $amount = (int) $payment->amount;

            if (! $wasCompleted && $isCompleted) {
                if ($amount > 0) {
                    Order::query()->whereKey($payment->order_id)->increment('paid_total', $amount);
                }

                return;
            }

            if (! $wasCompleted || ! $isCompleted || ! $payment->wasChanged('amount')) {
                return;
            }

            $delta = $amount - (int) $payment->getRawOriginal('amount');

            if ($delta > 0) {
                Order::query()->whereKey($payment->order_id)->increment('paid_total', $delta);
            } elseif ($delta < 0) {
                Order::query()->whereKey($payment->order_id)->decrement('paid_total', abs($delta));
            }
        });

        static::deleted(function (OrderPayment $payment): void {
            if ($payment->status === PaymentStatus::Completed && (int) $payment->amount > 0) {
                Order::query()->whereKey($payment->order_id)->decrement('paid_total', (int) $payment->amount);
            }
        });
    }

    private static function paymentStatusWas(OrderPayment $payment, PaymentStatus $status): bool
    {
        if (! $payment->wasChanged('status')) {
            return $payment->status === $status;
        }

        $original = $payment->getOriginal('status');

        return $original === $status || $original === $status->value;
    }

    private function assertTransactionIdentityIsAvailable(): void
    {
        if ($this->transaction_id === null || mb_trim($this->transaction_id) === '') {
            return;
        }

        $query = static::query()
            ->where('order_id', $this->order_id)
            ->where('gateway', $this->gateway)
            ->where('transaction_id', $this->transaction_id);

        if ($this->exists) {
            $query->where($this->getKeyName(), '!=', $this->getKey());
        }

        if ($query->exists()) {
            throw new InvalidArgumentException(
                'A payment with this order, gateway, and transaction identity already exists.',
            );
        }
    }
}
