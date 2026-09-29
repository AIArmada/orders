<?php

declare(strict_types=1);

namespace AIArmada\Orders\Models;

use AIArmada\CommerceSupport\Support\OwnerScope;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use AIArmada\Orders\Enums\OutboxStatus;
use AIArmada\Orders\Events\OrderFulfillmentRequired;
use AIArmada\Orders\Events\OrderProcessingStarted;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One replayable order event staged for at-least-once delivery.
 *
 * Rows are written in the same transaction as the order state change and
 * marked relayed by the live after-commit dispatch. The relay Action
 * re-dispatches rows the live path never marked (crash between commit
 * and dispatch). Consumers must therefore be idempotent.
 *
 * @property string $id
 * @property string $order_id
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string $event_class
 * @property string $transaction_id
 * @property string $gateway
 * @property OutboxStatus $status
 * @property int $attempts
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable|null $relayed_at
 * @property CarbonImmutable|null $next_retry_at
 * @property string|null $last_error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Order $order
 */
final class OrderOutboxMessage extends Model
{
    use HasOwner;
    use HasOwnerScopeConfig;
    use HasUuids;

    protected static string $ownerScopeConfigKey = 'orders.owner';

    /**
     * Events the relay is allowed to reconstruct. OrderPaid is excluded:
     * invoice creation and payment confirmation emails are not replayable.
     *
     * @var list<class-string>
     */
    public const REPLAYABLE_EVENTS = [
        OrderProcessingStarted::class,
        OrderFulfillmentRequired::class,
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'order_id',
        'owner_type',
        'owner_id',
        'event_class',
        'transaction_id',
        'gateway',
        'status',
        'attempts',
        'claimed_at',
        'relayed_at',
        'next_retry_at',
        'last_error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OutboxStatus::class,
            'attempts' => 'integer',
            'claimed_at' => 'immutable_datetime',
            'relayed_at' => 'immutable_datetime',
            'next_retry_at' => 'immutable_datetime',
        ];
    }

    public function getTable(): string
    {
        return config('orders.database.tables.order_outbox', 'order_outbox_messages');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * System operations run cross-tenant and re-scope per row: the
     * relay and sweep share this entry point instead of repeating
     * the opt-out at every call site.
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->withoutGlobalScope(OwnerScope::class);
    }

    public function scopePendingRelayable(Builder $query, CarbonImmutable $graceCutoff): Builder
    {
        return $query->where('status', OutboxStatus::Pending->value)
            ->where('created_at', '<', $graceCutoff);
    }

    public function scopeFailedRetryable(Builder $query, CarbonImmutable $now): Builder
    {
        return $query->where('status', OutboxStatus::Failed->value)
            ->where('next_retry_at', '<=', $now);
    }

    public function scopeStuckRelaying(Builder $query, CarbonImmutable $cutoff): Builder
    {
        return $query->where('status', OutboxStatus::Relaying->value)
            ->where('claimed_at', '<', $cutoff);
    }

    public function scopeRelayedBefore(Builder $query, CarbonImmutable $cutoff): Builder
    {
        return $query->where('status', OutboxStatus::Relayed->value)
            ->where('relayed_at', '<', $cutoff);
    }

    public function scopeDead(Builder $query): Builder
    {
        return $query->where('status', OutboxStatus::Dead->value);
    }
}
