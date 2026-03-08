<?php

declare(strict_types=1);

namespace AIArmada\Orders\Models;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\OwnerWriteGuard;
use AIArmada\CommerceSupport\Traits\HasOwner;
use AIArmada\CommerceSupport\Traits\HasOwnerScopeConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * @property string $id
 * @property string $order_id
 * @property string|null $user_id
 * @property string|null $owner_id
 * @property string|null $owner_type
 * @property string $content
 * @property bool $is_customer_visible
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Order $order
 * @property-read Model|null $user
 */
final class OrderNote extends Model
{
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
        'user_id',
        'content',
        'is_customer_visible',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_customer_visible' => false,
    ];

    public function getTable(): string
    {
        return config('orders.database.tables.order_notes', 'order_notes');
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
     * @return BelongsTo<Order, OrderNote>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Model, OrderNote>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model', Model::class);

        return $this->belongsTo($userModel, 'user_id');
    }

    // ─────────────────────────────────────────────────────────────
    // SCOPES
    // ─────────────────────────────────────────────────────────────

    /**
     * Scope to only internal notes.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInternal(Builder $query): Builder
    {
        return $query->where('is_customer_visible', false);
    }

    /**
     * Scope to only customer-visible notes.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCustomerVisible(Builder $query): Builder
    {
        return $query->where('is_customer_visible', true);
    }

    protected function casts(): array
    {
        return [
            'is_customer_visible' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OrderNote $note): void {
            if (! (bool) config('orders.owner.enabled', true)) {
                return;
            }

            if (blank($note->order_id)) {
                throw new InvalidArgumentException('order_id is required.');
            }

            $owner = OwnerContext::resolve();
            $includeGlobal = (bool) config('orders.owner.include_global', false);
            $order = OwnerWriteGuard::findOrFailForOwner(Order::class, $note->order_id, $owner, $includeGlobal);

            if ($order->owner_type !== null && $order->owner_id !== null) {
                $note->owner_type = $order->owner_type;
                $note->owner_id = $order->owner_id;
            } else {
                $note->owner_type = null;
                $note->owner_id = null;
            }
        });
    }
}
