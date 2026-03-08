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
 * @property string $type
 * @property string $first_name
 * @property string $last_name
 * @property string|null $company
 * @property string $line1
 * @property string|null $line2
 * @property string $city
 * @property string|null $state
 * @property string $postcode
 * @property string $country
 * @property string|null $owner_id
 * @property string|null $owner_type
 * @property string|null $phone
 * @property string|null $email
 * @property array|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Order $order
 */
final class OrderAddress extends Model
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
        'type',
        'first_name',
        'last_name',
        'company',
        'line1',
        'line2',
        'city',
        'state',
        'postcode',
        'country',
        'phone',
        'email',
        'metadata',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'shipping',
        'country' => 'MY',
    ];

    public function getTable(): string
    {
        return config('orders.database.tables.order_addresses', 'order_addresses');
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
     * @return BelongsTo<Order, OrderAddress>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    // ─────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────

    public function isBilling(): bool
    {
        return $this->type === 'billing';
    }

    public function isShipping(): bool
    {
        return $this->type === 'shipping';
    }

    public function getFullName(): string
    {
        return mb_trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Format as a single-line address.
     */
    public function getOneLine(): string
    {
        $parts = array_filter([
            $this->line1,
            $this->line2,
            $this->city,
            $this->state,
            $this->postcode,
            $this->country,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Format as multi-line address.
     */
    public function getFormatted(): string
    {
        $lines = array_filter([
            $this->getFullName(),
            $this->company,
            $this->line1,
            $this->line2,
            mb_trim("{$this->city}, {$this->state} {$this->postcode}"),
            $this->country,
        ]);

        return implode("\n", $lines);
    }

    /**
     * Convert to array for shipping/billing display.
     *
     * @return array<string, mixed>
     */
    public function toAddressArray(): array
    {
        return [
            'name' => $this->getFullName(),
            'company' => $this->company,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'state' => $this->state,
            'postcode' => $this->postcode,
            'country' => $this->country,
            'phone' => $this->phone,
            'email' => $this->email,
        ];
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OrderAddress $address): void {
            if (! (bool) config('orders.owner.enabled', true)) {
                return;
            }

            if (blank($address->order_id)) {
                throw new InvalidArgumentException('order_id is required.');
            }

            $owner = OwnerContext::resolve();
            $includeGlobal = (bool) config('orders.owner.include_global', false);
            $order = OwnerWriteGuard::findOrFailForOwner(Order::class, $address->order_id, $owner, $includeGlobal);

            if ($order->owner_type !== null && $order->owner_id !== null) {
                $address->owner_type = $order->owner_type;
                $address->owner_id = $order->owner_id;
            } else {
                $address->owner_type = null;
                $address->owner_id = null;
            }
        });
    }
}
