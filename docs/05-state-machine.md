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
                                           │
                                           ▼
                          ┌────────────────────────────────┐
                          │        PendingPayment          │
                          └────────┬──────────┬────────────┘
                                   │          │
            ┌──────────────────────┘          └───────────────────┐
            │                                                      │
            ▼                                                      ▼
    ┌───────────────┐                                      ┌─────────────────┐
    │  Processing   │                                      │  PaymentFailed  │
    └───────┬───────┘                                      │    (final)      │
            │                                              └─────────────────┘
            ├─────────────────────────────────┐
            │                                 │
            ▼                                 ▼
    ┌───────────────┐                 ┌───────────────┐
    │    Shipped    │                 │    OnHold     │
    └───────┬───────┘                 └───────────────┘
            │
            ▼
    ┌───────────────┐
    │   Delivered   │
    └───────┬───────┘
            │
    ┌───────┴───────┐
    │               │
    ▼               ▼
┌──────────┐  ┌──────────┐
│Completed │  │ Returned │───────┐
│ (final)  │  └──────────┘       │
└──────────┘                     ▼
                          ┌──────────┐
                          │ Refunded │
                          │ (final)  │
                          └──────────┘

       ┌─────────────────────────────────┐
       │ Cancelable from:                │
       │ • PendingPayment                │
       │ • Processing                    │
       │ • OnHold                        │
       └───────────────┬─────────────────┘
                       ▼
               ┌───────────────┐
               │   Canceled    │
               │   (final)     │
               └───────────────┘

               ┌───────────────┐
               │     Fraud     │
               │   (final)     │
               └───────────────┘
```

## States

| State | Description | Final | Can Cancel | Can Refund |
|-------|-------------|-------|------------|------------|
| `Created` | Initial state | No | Yes | No |
| `PendingPayment` | Awaiting payment | No | Yes | No |
| `Processing` | Payment received, preparing | No | Yes | No |
| `Shipped` | Order shipped | No | No | No |
| `Delivered` | Order delivered | No | No | No |
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
$service->processRefund($order, 5000, 'Returned items');
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

Register in the Order model or via configuration:

```php
// Extend the Order model
use Spatie\ModelStates\StateConfig;

public function registerStates(): void
{
    parent::registerStates();
    
    // Add custom state
    $this->addState(AwaitingPickup::class)
        ->allowTransition(Processing::class, AwaitingPickup::class)
        ->allowTransition(AwaitingPickup::class, Delivered::class);
}
```

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
