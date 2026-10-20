---
title: Troubleshooting
---

# Troubleshooting

## Common Issues

### "Order not found" when owner scoping is enabled

**Cause**: The order exists but belongs to a different owner context.

**Solution**:
1. Ensure `OwnerResolverInterface` is properly bound and returns the correct owner.
2. Check that the order was created with the correct `owner_id`/`owner_type`.
3. If you need to access global orders, set `include_global` to `true` in config.

```php
// Debug current owner context
use AIArmada\CommerceSupport\Support\OwnerContext;

dump(OwnerContext::resolve()); // Should return current tenant/owner
```

### State transition fails with "Invalid transition"

**Cause**: The current state doesn't allow transitioning to the target state.

**Solution**: Check allowed transitions for the current state:

```php
use AIArmada\Orders\States\Processing;

// Check current state
echo $order->status::class; // e.g., AIArmada\Orders\States\PendingPayment

// See what transitions are allowed
if ($order->status->canTransitionTo(Processing::class)) {
    // Transition is valid
}
```

### Order totals don't match

**Cause**: Totals weren't recalculated after modifying items.

**Solution**: Always call `recalculateTotals()` after modifying items:

```php
$service = app(OrderServiceInterface::class);

$service->addItem($order, [...]);
$service->recalculateTotals($order);
```

### Invoice generation fails

**Cause**: Missing `spatie/laravel-pdf` configuration or Chromium not installed.

**Solution**:
1. Install Browsershot dependencies:
```bash
npm install puppeteer
```

2. Or configure a different PDF driver in `spatie/laravel-pdf` config.

### PaymentStatus enum errors

**Cause**: Using raw strings instead of the enum.

**Solution**: Always use the `PaymentStatus` enum:

```php
use AIArmada\Orders\Enums\PaymentStatus;

// Correct
$payment->status = PaymentStatus::Completed;

// Wrong - will cause type errors
$payment->status = 'completed';
```

### Cascade deletes not working

**Cause**: The package uses application-level cascades, not database cascades.

**Solution**: Ensure you're deleting via Eloquent:

```php
// Correct - triggers cascades
$order->delete();

// Wrong - bypasses Eloquent, cascades won't run
DB::table('orders')->where('id', $orderId)->delete();
```

### Child record parent move throws "immutable after creation"

**Cause**: `order_id` on `OrderItem`, `OrderNote`, `OrderPayment`, or
`OrderRefund` (and `payment_id` on refunds) is immutable once persisted.

**Solution**: Child records belong to the order they were created against.
Create a new child record on the target order instead of moving a persisted
one:

```php
// Wrong - throws InvalidArgumentException
$item->order_id = $otherOrder->id;
$item->save();

// Correct - create a fresh line on the target order
$otherOrder->items()->create([
    'name' => $item->name,
    'quantity' => $item->quantity,
    'unit_price' => $item->unit_price,
    'currency' => $item->currency,
]);
```

A refund `payment_id` must also reference a payment of the same order; refund
actions (`RegisterOrderRefund`) pick the payment for you. A refund's
`allocation_key` (its request identity) is equally immutable, while its
`transaction_id` is replaced with the provider refund id on completion.

### Refund rejected with "cannot exceed the remaining refundable amount"

**Cause**: The amount exceeds the money actually received on the order (or
on the specified payment).

**Solution**: `RefundAllocator::remainingForOrder()` is `paid_total` minus
settled minus pending refunds; `RefundAllocator::remainingForPayment()` is the
payment's amount minus refunds already attributed to it, and only
Completed/Refunded payments have capacity at all: attributing a refund to a
payment that never received money (Pending or Failed) is rejected with
`The specified payment has not received any refundable money.` There is no
grand-total fallback, so an order that never received money reserves nothing.
A refund larger than one receipt splits FIFO across the payments that received
money instead of over-allocating a single receipt:

```php
// Two 5,000 receipts, 7,000 refund: 5,000 on the first receipt,
// 2,000 on the second.
$refunds = app(RegisterOrderRefund::class)->createPending(
    order: $order,
    amount: 7000,
    transactionId: 'ref_split_1',
    reason: 'Customer return',
);
```

Passing `payment:` attributes the whole refund to that payment and is rejected
with a `specified payment` message when the payment cannot cover it.

### Health check shows orders stuck

**Cause**: Orders in `Processing` state haven't been shipped.

**Solution**: Process the fulfillment queue or investigate why orders aren't being fulfilled:

```php
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\States\Processing;

// Find stuck orders
$stuck = Order::whereState('status', Processing::class)
    ->where('created_at', '<', now()->subHours(48))
    ->get();

foreach ($stuck as $order) {
    // Log or alert about this order
    Log::warning("Order {$order->order_number} stuck in processing");
}
```

## Performance Tips

### Use eager loading

```php
// Avoid N+1 queries
$orders = Order::with([
    'items',
    'payments',
    'refunds',
    'orderNotes',
    'addresses',
])->paginate();
```

`Order` has no `billingAddress` / `shippingAddress` relations — eager-load
`addresses` and read the typed copy with `primaryAddress('billing')`.

### Cache expensive queries

The package includes `FilamentOrdersCache` for Filament widgets. For custom queries:

```php
$stats = Cache::remember('order-stats', 60, function () {
    return [
        'total' => Order::forOwner()->count(),
        'pending' => Order::forOwner()->whereState('status', PendingPayment::class)->count(),
    ];
});
```

### Index important columns

The migrations include indexes on:
- `status`
- `order_number`
- `created_at`
- `paid_at`
- `customer_type` + `customer_id`

Add additional indexes for custom query patterns:

```php
Schema::table('orders', function (Blueprint $table) {
    $table->index(['owner_type', 'owner_id', 'paid_at']);
});
```

## Debugging

### Enable query logging

```php
DB::enableQueryLog();

// Your code here
$order = Order::with('items')->find($id);

dd(DB::getQueryLog());
```

### Check state machine configuration

```php
use AIArmada\Orders\States\OrderStatus;

// Get the state config with all registered states and transitions
$config = OrderStatus::config();
dump($config);
```

### Validate owner scoping

```php
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Orders\Models\Order;

// Check what owner is being used
dump([
    'resolved_owner' => OwnerContext::resolve(),
    'orders_owner_enabled' => config('orders.owner.enabled'),
    'orders_include_global' => config('orders.owner.include_global'),
]);

// Check raw query being generated
dump(Order::forOwner()->toSql());
```

## Getting Help

1. Check the [Configuration](03-configuration.md) documentation
2. Review the [State Machine](05-state-machine.md) documentation
3. Examine error logs in `storage/logs/laravel.log`
4. Open an issue on the GitHub repository
