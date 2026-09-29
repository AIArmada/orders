---
title: State Machine
---

# Order State Machine

The Orders package uses `spatie/laravel-model-states` for robust order state management.

## State Diagram

```
              ┌─────────────┐
              │   Created   │
              └──────┬──────┘
                     ▼
         ┌─────────────────────┐
         │    PendingPayment   │
         └──┬───────┬───────┬──┘
            ▼       ▼       ▼
     ┌────────────┐ ┌───────────┐ ┌─────────────┐
     │ Processing │ │ Canceled  │ │PaymentFailed│
     └─────┬──────┘ │  (final)  │ │   (final)   │
           ▼        └───────────┘ └─────────────┘
    ┌────────────┐
    │  Shipped   │──┐
    └─────┬──────┘  │ (Shipped → Returned)
          ▼         ▼
   ┌─────────────┐ ┌──────────┐
   │  Delivered  │ │ Returned │──┐
   └──────┬──────┘ └──────────┘  ▼
          ▼               ┌────────────┐
   ┌──────────┐           │  Refunded  │
   │Completed │──────────▶│  (final)   │
   │ (final)  │           └────────────┘
   └──────────┘
   ┌─────────┐    ┌─────────┐
   │ OnHold  │    │  Fraud  │
   └─────────┘    │ (final) │
   (Processing    └─────────┘
    ↔ OnHold)
```

The sketch above shows the primary flow. The complete edge list
(`OrderStatus::config()`) is authoritative:

- Created → PendingPayment, Processing
- PendingPayment → Processing, Canceled, PaymentFailed
- Processing → OnHold, Fraud, Shipped, Completed, Canceled, Refunded
- OnHold → Processing, Canceled
- Shipped → Delivered, Returned
- Delivered → Completed, Returned, Refunded
- Completed → Refunded
- Canceled → Refunded
- Returned → Refunded

Cancelable from: Created, PendingPayment, Processing, OnHold.

## States

| State | Description | Final | Can Cancel | Can Refund |
|-------|-------------|-------|------------|------------|
| `Created` | Initial state | No | Yes | No |
| `PendingPayment` | Awaiting payment | No | Yes | No |
| `Processing` | Payment received, preparing | No | Yes | Yes |
| `Shipped` | Order shipped | No | No | No |
| `Delivered` | Order delivered | No | No | Yes |
| `Completed` | Fully completed | Yes | No | Yes |
| `Canceled` | Order canceled | Yes | No | No |
| `Refunded` | Fully refunded | Yes | No | No |
| `Returned` | Items returned | No | No | Yes |
| `OnHold` | Manual review needed | No | Yes | No |
| `Fraud` | Fraud detected | Yes | No | No |
| `PaymentFailed` | Payment failed | Yes | No | No |

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

`Delivered` → `Returned`

Records `returned_at` as a historical fact (mirrors `order_items.returned_at` at the order level). A subsequent refund transitions `Returned` → `Refunded`.

```php
use AIArmada\Orders\Transitions\OrderReturned;

$order->status->transitionTo(Returned::class, new OrderReturned(
    $order,
    reason: 'Damaged goods',
));
```

### RefundProcessed

`Returned` → `Refunded`

```php
use AIArmada\Orders\Transitions\RefundProcessed;

$order->status->transitionTo(Refunded::class, new RefundProcessed(
    $order,
    amount: 5000,
    reason: 'Items returned',
    transactionId: 'ref_789',
));
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
$service->cancel($order, 'Customer requested', auth()->id());
$service->processRefund($order, 5000, 'ref_456', 'Returned items');
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

States are registered centrally in `AIArmada\Orders\States\OrderStatus::config()`,
which is `final`. The package does not offer a runtime hook for adding states:
to introduce one, extend the `OrderStatus` hierarchy in a fork or package
override and register the new state plus its edges on the `StateConfig`:

```php
use AIArmada\Orders\States\OrderStatus;
use Spatie\ModelStates\StateConfig;

OrderStatus::config()
    ->registerState(AwaitingPickup::class)
    ->allowTransition(Processing::class, AwaitingPickup::class)
    ->allowTransition(AwaitingPickup::class, Delivered::class);
```

Note that `OrderStatus::config()` builds a fresh `StateConfig` on every call,
so registrations must live in the state class itself — there is no
model-level `registerStates()` hook in this package.

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

Free orders reach the same consumers through a dedicated `FreeOrderConfirmed` transition: it moves the order to Processing and dispatches `OrderProcessingStarted` plus `OrderFulfillmentRequired` after commit. It is deliberately not `PaymentConfirmed` — there is no payment record, no paid timestamp, and no `OrderPaid` event. Affiliate attribution still runs (and abstains on the zero value); pass issuance, event registration sync, and promotion usage counting all run for free orders. Only invoice creation and payment confirmation emails stay paid-only.

A free confirmation requires all of the following, checked in order:

1. `grand_total <= 0` — nothing is owed. A zero *balance* alone is not enough: a fully paid order also owes nothing.
2. `paid_total === 0` — nothing was paid, including partial or out-of-band payments.
3. The order is not already Processing (idempotent no-op when it is and the money guards pass).
4. The current state is `Created` or `PendingPayment`. Held, canceled, and failed orders are rejected instead of being dragged back into Processing.

Violations throw `InvalidArgumentException` before any state change. State rejections (check 4) use the `OrderNotAwaitingPayment` subclass so callers can classify the cause from the exception instead of re-reading the row.

> [!WARNING]
> Known limitations of the free path, shared with the paid path where noted:
>
> - No `OrderPaid` means `OrderPaid` consumers never run for free orders: no invoice and no payment confirmation email. Pass issuance, event registration sync, promotion usage counting, and commission attribution run through the fulfillment event instead.
> - Like `PaymentConfirmed`, the dispatch is commit-then-event backed by the transactional outbox. If the process dies between commit and the after-commit callbacks, the `orders:outbox-relay` command re-dispatches the staged rows; `orders:outbox-sweep` reconciles stuck rows and purges relayed history.
> - A free order in Processing reports `isPaid() === false` and is excluded from the "Unpaid Orders" Filament filter (zero-total orders are neither paid nor unpaid). Revenue sums keyed on `paid_at IS NOT NULL` correctly exclude it.

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
