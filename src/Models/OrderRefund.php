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
use AIArmada\Orders\Enums\RefundStatus;
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
 * @property string|null $payment_id
 * @property string|null $owner_id
 * @property string|null $owner_type
 * @property string $gateway
 * @property string|null $transaction_id
 * @property int $amount
 * @property string $currency
 * @property RefundStatus $status
 * @property string $reason
 * @property string|null $notes
 * @property array|null $metadata
 * @property CarbonInterface|null $refunded_at
 * @property CarbonInterface|null $failed_at
 * @property CarbonInterface|null $provider_submission_started_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Order $order
 * @property-read OrderPayment|null $payment
 */
final class OrderRefund extends Model implements Auditable
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
        'payment_id',
        'gateway',
        'transaction_id',
        'amount',
        'currency',
        'status',
        'reason',
        'notes',
        'metadata',
        'refunded_at',
        'failed_at',
        'provider_submission_started_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => RefundStatus::Pending,
        'currency' => 'MYR',
    ];

    public function getTable(): string
    {
        return config('orders.database.tables.order_refunds', 'order_refunds');
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
     * @return BelongsTo<Order, OrderRefund>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<OrderPayment, OrderRefund>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(OrderPayment::class, 'payment_id');
    }

    // ─────────────────────────────────────────────────────────────
    // STATUS HELPERS
    // ─────────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === RefundStatus::Pending;
    }

    public function isCompleted(): bool
    {
        return $this->status === RefundStatus::Completed;
    }

    public function isFailed(): bool
    {
        return $this->status === RefundStatus::Failed;
    }

    public function hasProviderSubmissionStarted(): bool
    {
        return $this->provider_submission_started_at !== null;
    }

    public function markAsCompleted(?string $transactionId = null): self
    {
        $this->status = RefundStatus::Completed;
        $this->refunded_at = CarbonImmutable::now();

        if ($transactionId !== null) {
            $this->transaction_id = $transactionId;
        }

        $this->save();

        return $this;
    }

    public function markAsFailed(string $reason): self
    {
        $this->status = RefundStatus::Failed;
        $this->failed_at = CarbonImmutable::now();
        $this->notes = $reason;
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
            'status' => RefundStatus::class,
            'metadata' => 'array',
            'refunded_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'provider_submission_started_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OrderRefund $refund): void {
            if (! (bool) config('orders.owner.enabled', false)) {
                return;
            }

            if (blank($refund->order_id)) {
                throw new InvalidArgumentException('order_id is required.');
            }

            if (! Order::ownerScopeConfig()->enabled) {
                $order = Order::query()->findOrFail($refund->order_id);
            } else {
                $owner = OwnerContext::resolve();
                $includeGlobal = (bool) config('orders.owner.include_global', false);
                $order = OwnerWriteGuard::findOrFailForOwner(Order::class, $refund->order_id, $owner, $includeGlobal);
            }

            if ($order->owner_type !== null && $order->owner_id !== null) {
                $refund->owner_type = $order->owner_type;
                $refund->owner_id = $order->owner_id;
            } else {
                $refund->owner_type = null;
                $refund->owner_id = null;
            }
        });

        // Keep the parent order's cached refund totals in sync for every write
        // path using atomic increments. Pending holds reservations, Completed
        // holds settled refunds; Failed holds nothing.
        static::created(function (OrderRefund $refund): void {
            $column = self::refundTotalColumn($refund->status);
            $amount = (int) $refund->amount;

            if ($column === null || $amount <= 0) {
                return;
            }

            Order::query()->whereKey($refund->order_id)->increment($column, $amount);
        });

        static::updated(function (OrderRefund $refund): void {
            if (! $refund->wasChanged('status')
                && ! $refund->wasChanged('amount')
            ) {
                return;
            }

            $oldColumn = self::refundTotalColumn($refund->getRawOriginal('status'));
            $newColumn = self::refundTotalColumn($refund->status);
            $oldAmount = (int) $refund->getRawOriginal('amount');
            $newAmount = (int) $refund->amount;

            if ($oldColumn === $newColumn) {
                if ($newColumn === null) {
                    return;
                }

                $delta = $newAmount - $oldAmount;

                if ($delta > 0) {
                    Order::query()->whereKey($refund->order_id)->increment($newColumn, $delta);
                } elseif ($delta < 0) {
                    Order::query()->whereKey($refund->order_id)->decrement($newColumn, abs($delta));
                }

                return;
            }

            if ($oldColumn !== null && $oldAmount > 0) {
                Order::query()->whereKey($refund->order_id)->decrement($oldColumn, $oldAmount);
            }

            if ($newColumn !== null && $newAmount > 0) {
                Order::query()->whereKey($refund->order_id)->increment($newColumn, $newAmount);
            }
        });

        static::deleted(function (OrderRefund $refund): void {
            $column = self::refundTotalColumn($refund->status);
            $amount = (int) $refund->amount;

            if ($column === null || $amount <= 0) {
                return;
            }

            Order::query()->whereKey($refund->order_id)->decrement($column, $amount);
        });
    }

    private static function refundTotalColumn(RefundStatus | string | null $status): ?string
    {
        $status = $status instanceof RefundStatus
            ? $status
            : RefundStatus::tryFrom((string) $status);

        return match ($status) {
            RefundStatus::Pending => 'pending_refunded_total',
            RefundStatus::Completed => 'refunded_total',
            default => null,
        };
    }
}
