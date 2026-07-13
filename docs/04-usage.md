---
title: Usage
---

# Usage Guide

## Canonical API: Actions

The canonical orchestration surface is the `Actions` tree. Prefer these over direct service calls:

### Creating Orders

```php
use AIArmada\Orders\Actions\CreateOrder;
use AIArmada\Orders\Actions\CreateOrderFromCart;

// Basic creation
$order = CreateOrder::run([
    'currency' => 'MYR',
    'notes' => 'Customer special instructions',
]);

// From cart
use AIArmada\Cart\Models\Cart;

$cart = Cart::find($cartId);
$order = CreateOrderFromCart::run($cart, [
    'notes' => 'Optional notes',
]);
```

### Durable Intake Identity

Prevent duplicate orders from retries and concurrent submissions using intake identity. Pass `intakeSource` and `intakeId` to make order creation idempotent:

```php
use AIArmada\Orders\Actions\CreateOrder;

// Idempotent creation — same intake identity returns the existing order
$order = CreateOrder::run([
    'currency' => 'MYR',
    'subtotal' => 5000,
    'grand_total' => 5000,
], intakeSource: 'checkout', intakeId: 'sess_abc123');

// Exact retry — returns the same order, no duplicate
$retry = CreateOrder::run([
    'currency' => 'MYR',
    'subtotal' => 5000,
    'grand_total' => 5000,
], intakeSource: 'checkout', intakeId: 'sess_abc123');

assert($retry->id === $order->id); // Same order
```

**Intake identity guarantees:**

- **Same intake (source + id) returns the existing order.** Items, addresses, and relationships are loaded on the returned model.
- **Database-level unique constraint** on `(owner_type, owner_id, intake_source, intake_id)` prevents concurrent duplicates.
- **Without intake identity,** each call creates a new order (backward compatible).
- **Different source with same id** creates separate orders (e.g., `checkout` vs `api` with same session id).

The Checkout `CreateOrderStep` uses `intakeSource: 'checkout'` with the session id as `intakeId`.

### Payment & Refunds

```php
use AIArmada\Orders\Actions\RegisterOrderPayment;
use AIArmada\Orders\Actions\RegisterOrderRefund;

// Confirm payment
RegisterOrderPayment::run(
    order: $order,
    transactionId: 'txn_abc123',
    gateway: 'stripe',
    amount: 9900, // cents - partial payments supported
);

// Process refund
RegisterOrderRefund::run(
    order: $order,
    amount: 5000, // cents
    reason: 'Customer requested refund',
    transactionId: 'ref_xyz789',
);
```

### Cancellation & Completion

```php
use AIArmada\Orders\Actions\CancelOrder;
use AIArmada\Orders\Actions\CompleteOrder;

// Cancel
CancelOrder::run(
    order: $order,
    reason: 'Customer requested cancellation',
    canceledBy: auth()->id(),
);

// Complete (marks as delivered)
CompleteOrder::run($order);
```

## OrderService (Compatibility)

The `OrderServiceInterface` is kept as a compatibility layer that delegates to Actions. Use it for existing code:

```php
use AIArmada\Orders\Contracts\OrderServiceInterface;

class CheckoutController
{
    public function __construct(
        private OrderServiceInterface $orderService
    ) {}
}
```

Available through the service:

| Method | Delegates To |
|--------|-------------|
| `createOrder()` | `CreateOrder` |
| `createFromCart()` | `CreateOrderFromCart` |
| `cancel()` | `CancelOrder` |
| `confirmPayment()` | `RegisterOrderPayment` |
| `processRefund()` | `RegisterOrderRefund` |
| `ship()` | Via `OrderHandlerRegistrar` |
| `confirmDelivery()` | `CompleteOrder` |

## Working with Models Directly

### Query Orders

```php
use AIArmada\Orders\Models\Order;

// Get orders for current owner (multi-tenant)
$orders = Order::query()
    ->forOwner(includeGlobal: false)
    ->with(['items', 'payments'])
    ->latest()
    ->paginate();

// Get specific order
$order = Order::query()
    ->forOwner()
    ->with(['items', 'billingAddress', 'shippingAddress', 'payments'])
    ->findOrFail($orderId);
```

### Check Order State

```php
use AIArmada\Orders\States\PendingPayment;
use AIArmada\Orders\States\Processing;

// Check specific state
if ($order->status instanceof PendingPayment) {
    // Handle pending payment
}

// Check if order can be modified
if ($order->status->canModify()) {
    // Allow modifications
}

// Check if order can be canceled
if ($order->status->canCancel()) {
    // Show cancel button
}

// Check if order is in final state
if ($order->status->isFinal()) {
    // No more transitions possible
}
```

### Money Formatting

```php
// Format currency values
echo $order->formattedSubtotal();    // "MYR 99.00"
echo $order->formattedGrandTotal();  // "MYR 119.00"

// Check payment status
if ($order->isPaid()) {
    // Order has been paid
}

if ($order->isFullyPaid()) {
    // Total payments >= grand total
}
```

## Events

The package dispatches events during order lifecycle:

| Event | Description |
|-------|-------------|
| `OrderCreated` | Order was created |
| `OrderProcessingStarted` | Order entered processing state |
| `OrderPaid` | Payment was confirmed |
| `OrderShipped` | Order was shipped |
| `OrderDelivered` | Order was delivered |
| `OrderCancelInitiated` | Cancellation workflow started |
| `OrderCanceled` | Order was canceled |
| `OrderRefunded` | Refund was processed |
| `OrderPaymentFailed` | Payment attempt failed |
| `InventoryDeductionRequired` | Inventory reservation needed |
| `InventoryReleaseRequired` | Inventory release needed |
| `CommissionAttributionRequired` | Commission attribution needed |

### Listening to Events

```php
// EventServiceProvider.php
use AIArmada\Orders\Events\OrderPaid;
use App\Listeners\SendOrderConfirmation;

protected $listen = [
    OrderPaid::class => [
        SendOrderConfirmation::class,
    ],
];
```

### Event Properties

```php
// OrderPaid event
class SendOrderConfirmation
{
    public function handle(OrderPaid $event): void
    {
        $order = $event->order;
        $transactionId = $event->transactionId;
        $gateway = $event->gateway;
        
        // Send confirmation email
    }
}
```

## Order Documents

### Persisted Invoice Documents

Use `CreateOrderInvoiceDoc` when you want to create a Docs record for a paid order.

Automatic `OrderPaid` invoice generation is disabled by default. Turn on `orders.integrations.docs.enabled` only when you want that event listener to create persisted Docs invoices automatically.

```php
use AIArmada\Orders\Actions\CreateOrderInvoiceDoc;

$invoice = app(CreateOrderInvoiceDoc::class)->execute(
    order: $order,
    transactionId: 'txn_abc123',
    gateway: 'chip',
);
```

The action re-enters the order's owner scope automatically and creates a Docs invoice only when one does not already exist for that order.

### Persisted Receipt Documents

Use `CreateOrderReceiptDoc` when payment confirmation should also create a receipt document.

Checkout-driven receipt generation is also opt-in from the checkout package side; manual action usage remains available regardless of the listener defaults.

```php
use AIArmada\Orders\Actions\CreateOrderReceiptDoc;

$receipt = app(CreateOrderReceiptDoc::class)->execute(
    order: $order,
    transactionId: 'txn_abc123',
    gateway: 'chip',
);
```

Unlike invoice creation, receipt creation is idempotent by returning the existing receipt document when one is already present.

Both actions share the same internal order-doc builder, so customer data, order totals, tax, discount, gateway metadata, and owner scope handling stay aligned.

### PDF Invoice Output

```php
use AIArmada\Orders\Actions\GenerateInvoice;

$generator = app(GenerateInvoice::class);

// Get PDF response for download
return $generator->download($order);

// Get PDF content as string
$pdfContent = $generator->generate($order);
```

Use `GenerateInvoice` for ad-hoc PDF generation and download responses. Use `CreateOrderInvoiceDoc` / `CreateOrderReceiptDoc` when you want persisted Docs records that integrate with the Docs package.

## Health Checks

Register the health check for monitoring:

```php
use AIArmada\Orders\Health\OrderProcessingCheck;
use Spatie\Health\Facades\Health;

Health::checks([
    OrderProcessingCheck::new(),
]);
```

This monitors:
- Orders stuck in processing state for too long
- Payment processing delays
- Fulfillment bottlenecks
