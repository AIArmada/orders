---
title: State Machine
---

# Order State Machine

The Orders package uses `spatie/laravel-model-states` for robust order state management.

## State Diagram

The authoritative source is `OrderStatus::config()` in
`packages/orders/src/States/OrderStatus.php`. The default state is `Created`.
These 21 transitions — and only these — are allowed:

| From | To |
|------|----|
| `Created` | `PendingPayment` |
| `Created` | `Processing` |
| `PendingPayment` | `Processing` |
| `PendingPayment` | `Canceled` |
| `PendingPayment` | `PaymentFailed` |
| `Processing` | `OnHold` |
| `Processing` | `Fraud` |
| `Processing` | `Shipped` |
| `Processing` | `Completed` |
| `Processing` | `Canceled` |
| `Processing` | `Refunded` |
| `OnHold` | `Processing` |
| `OnHold` | `Canceled` |
| `Shipped` | `Delivered` |
| `Shipped` | `Returned` |
| `Delivered` | `Completed` |
| `Delivered` | `Returned` |
| `Delivered` | `Refunded` |
| `Completed` | `Refunded` |
| `Canceled` | `Refunded` |
| `Returned` | `Refunded` |

Cancellable states are `PendingPayment`, `Processing`, and `OnHold` — there is no
`Created → Canceled` transition. `Completed`, `Canceled`, `Fraud`, and `PaymentFailed`
all report `isFinal() === true` while still allowing the outbound `→ Refunded` edge.

## States

| State | Description | Final | Can Cancel | Can Refund | Can Modify |
|-------|-------------|-------|------------|------------|------------|
| `Created` | Initial state (`public static string $name = 'created'`) | No | Yes | No | Yes |
| `PendingPayment` | Awaiting payment | No | Yes | No | Yes |
| `Processing` | Payment received, preparing | No | Yes | Yes | No |
| `Shipped` | Order shipped | No | No | No | No |
| `Delivered` | Order delivered | No | No | Yes | No |
| `Completed` | Fully completed | Yes | No | Yes | No |
| `Canceled` | Order canceled | Yes | No | No | No |
| `Refunded` | Fully refunded | Yes | No | No | No |
| `Returned` | Items returned | No | No | Yes | No |
| `OnHold` | Manual review needed | No | Yes | No | No |
| `Fraud` | Fraud detected | Yes | No | No | No |
| `PaymentFailed` | Payment failed | Yes | No | No | No |

Those 12 concrete states are the complete set in `AIArmada\Orders\States`.

> **info**
> `Created::canCancel()` returns `true`, but `OrderStatus::config()` does not allow
> `Created → Canceled`, so a transition on a freshly created order throws. Treat
> `canCancel()` on `Created` as a reporting bug in the package, not a supported flow.

## State Methods

Each state class provides these methods:

```php
use AIArmada\Orders\States\OrderStatus;

// Get state display information
$order->status->label();  // "Pending Payment"
$order->status->color();  // "warning"
$order->status->icon();   // "heroicon-o-clock"

// Check capabilities
$order->status->canCancel();  // bool
$order->status->canRefund();  // bool
$order->status->canModify();  // bool
$order->status->isFinal();    // bool
```

## Transitions

Transitions are explicit classes that handle state changes:

### PaymentConfirmed

`PendingPayment` → `Processing`

```php
use AIArmada\Orders\Transitions\PaymentConfirmed;

$order->status->transitionTo(Processing::class, new PaymentConfirmed(
    $order,
    transactionId: 'txn_123',
    gateway: 'stripe',
    amount: 9900,
));
```

### ShipmentCreated

`Processing` → `Shipped`

```php
use AIArmada\Orders\Transitions\ShipmentCreated;

$order->status->transitionTo(Shipped::class, new ShipmentCreated(
    $order,
    carrier: 'DHL',
    trackingNumber: 'DHL123',
    shipmentId: 'ship_456',
    metadata: ['weight' => 500],
));
```

### DeliveryConfirmed

`Shipped` → `Delivered`

```php
use AIArmada\Orders\Transitions\DeliveryConfirmed;

$order->status->transitionTo(Delivered::class, new DeliveryConfirmed($order));
```

### OrderCanceled

`PendingPayment|Processing|OnHold` → `Canceled`

```php
use AIArmada\Orders\Transitions\OrderCanceled;

$order->status->transitionTo(Canceled::class, new OrderCanceled(
    $order,
    reason: 'Customer requested',
    canceledBy: auth()->id(),
));
```

### OrderHeld

`Processing` → `OnHold`

Records `held_at` as a toggle timestamp (set on hold, cleared by `OrderHoldReleased`).

```php
use AIArmada\Orders\Transitions\OrderHeld;

$order->status->transitionTo(OnHold::class, new OrderHeld(
    $order,
    reason: 'Manual review',
    heldBy: auth()->id(),
));
```

### OrderHoldReleased

`OnHold` → `Processing`

Clears `held_at` to signal the order is no longer on hold.

```php
use AIArmada\Orders\Transitions\OrderHoldReleased;

$order->status->transitionTo(Processing::class, new OrderHoldReleased(
    $order,
    reason: 'Approved after review',
));
```

### OrderFlaggedAsFraud

`Processing` → `Fraud`

Records `flagged_at` once. Fraud is terminal — the timestamp is never cleared.

```php
use AIArmada\Orders\Transitions\OrderFlaggedAsFraud;

$order->status->transitionTo(Fraud::class, new OrderFlaggedAsFraud(
    $order,
    reason: 'Chargeback pattern detected',
    flaggedBy: auth()->id(),
));
```

### OrderReturned

`Shipped|Delivered` → `Returned`

Records `returned_at` as a historical fact (mirrors `order_items.returned_at` at the order level). A subsequent refund transitions `Returned` → `Refunded`.

```php
use AIArmada\Orders\Transitions\OrderReturned;

$order->status->transitionTo(Returned::class, new OrderReturned(
    $order,
    reason: 'Damaged goods',
));
```

### RefundProcessed

`Processing|Delivered|Completed|Canceled|Returned → Refunded`

`OrderServiceInterface::processRefund()` is documented for the returned-items path, but
the transition itself is allowed from every one of those source states.

```php
use AIArmada\Orders\Transitions\RefundProcessed;

$order->status->transitionTo(Refunded::class, new RefundProcessed(
    $order,
    amount: 5000,
    reason: 'Items returned',
    transactionId: 'ref_789',
));
```

### PaymentFailed

`PendingPayment → PaymentFailed`

Marks the pending `OrderPayment` as failed and records `payment_failed_at`.

```php
use AIArmada\Orders\Transitions\PaymentFailed as PaymentFailedTransition;

$order->status->transitionTo(PaymentFailed::class, new PaymentFailedTransition(
    $order,
    reason: 'Card declined',
));
```

> **warning**
> The `PaymentFailed` *transition* class shares its short name with the
> `AIArmada\Orders\States\PaymentFailed` *state* class. Alias one of them when both are
> in scope, as the package does internally (`PaymentFailedState`).

### OrderCompleted

`Processing|Delivered → Completed`

The no-shipping path for digital goods and admissions. Records `completed_at`.

```php
use AIArmada\Orders\Transitions\OrderCompleted;

$order->status->transitionTo(Completed::class, new OrderCompleted($order));
```

### RefundCompleted

Takes an `OrderRefund` (not an `Order`) and only completes a refund already in
`RefundStatus::Pending`:

```php
use AIArmada\Orders\Transitions\RefundCompleted;

// handle() takes no arguments — the refund is passed to the constructor
$order = (new RefundCompleted($refund, 'ref_789'))->handle();
```

## Using OrderService

The recommended way to transition states is through `OrderService`:

```php
use AIArmada\Orders\Contracts\OrderServiceInterface;

// Preferred approach
$service = app(OrderServiceInterface::class);

$service->confirmPayment($order, 'txn_123', 'stripe', 9900);
$service->ship($order, 'DHL', 'DHL123');
$service->confirmDelivery($order);
$service->complete($order);
$service->cancel($order, 'Customer requested', auth()->id());

// processRefund() takes transactionId as the 3rd argument and reason as the 4th.
// Both are required — a 3-argument call is an ArgumentCountError.
$service->processRefund($order, 5000, 'ref_789', 'Returned items');
```

## Custom State Logic

### Extending States

```php
namespace App\Orders\States;

use AIArmada\Orders\States\OrderStatus;

final class AwaitingPickup extends OrderStatus
{
    public static string $name = 'awaiting_pickup';

    public function color(): string
    {
        return 'info';
    }

    public function icon(): string
    {
        return 'heroicon-o-building-storefront';
    }

    public function label(): string
    {
        return 'Awaiting Pickup';
    }

    public function canCancel(): bool
    {
        return true;
    }
}
```

### Registering Custom States

> **warning**
> The state set is closed. `OrderStatus::config()` is declared `final`, and `Order` binds the field with `casts()` → `'status' => OrderStatus::class` — it does not override `registerStates()`. A subclass that calls `$this->addState(...)` after `parent::registerStates()` cannot compile, and calling `allowTransition()` on the final config throws.

Subclassing `OrderStatus` therefore produces a state that is **never reachable**:

```php
// Compiles fine, but `status = 'awaiting_pickup'` is rejected — the field
// casts to OrderStatus::class, which resolves only the 12 shipped states.
final class AwaitingPickup extends OrderStatus
{
    public static string $name = 'awaiting_pickup';

    public function color(): string
    {
        return 'info';
    }

    public function icon(): string
    {
        return 'heroicon-o-building-storefront';
    }

    public function label(): string
    {
        return 'Awaiting Pickup';
    }

    public function canCancel(): bool
    {
        return true;
    }
}
```

To ship a new lifecycle state you must change the package: drop the `final` on
`OrderStatus::config()`, add the `allowTransition()` edges, and point the `status` cast
at a state base class that knows about the new state. Custom presentation of the existing
states (label, color, icon, capabilities) needs no code change at all — the twelve state
classes are not final.

## Querying by State

```php
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\States\Processing;
use AIArmada\Orders\States\PendingPayment;

// Orders in specific state
$processing = Order::whereState('status', Processing::class)->get();

// Orders in multiple states
$pending = Order::whereState('status', [
    PendingPayment::class,
    Processing::class,
])->get();

// Orders not in state
$notShipped = Order::whereNotState('status', Shipped::class)->get();
```

## Inventory Integration Event Path

When inventory integration is enabled (`orders.integrations.inventory.enabled`), the Orders package dispatches inventory commands through a single canonical event path per operation:

### Deduction (Payment Confirmed)

```
PaymentConfirmed transition completes
  → (after DB commit)
  → OrderProcessingStarted event
  → DeductInventoryOnPaymentConfirmed listener
  → InventoryDeductionRequired event
  → Inventory package handles deduction once
```

Inventory deduction is not dispatched synchronously from the transition itself. This guarantees one logical deduction per payment confirmation, even under duplicate event delivery.

### Release (Order Canceled)

```
OrderCanceled transition completes
  → (after DB commit)
  → OrderCancelInitiated event
  → ReleaseInventoryOnOrderCanceled listener
  → InventoryReleaseRequired event
  → Inventory package handles release once
```

Inventory release is not dispatched synchronously from the transition itself. This guarantees one logical release per cancellation, even under duplicate event delivery.

### Idempotency

The Inventory package uses a durable `InventoryOperation` record with a unique key on `(order_id, kind)` to prevent duplicate inventory mutations from retried or replayed events. Both deduction and release operations are safe to deliver more than once.
