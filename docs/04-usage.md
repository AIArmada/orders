---
title: Usage
---

# Usage Guide

## OrderService

The `OrderService` is the primary interface for order management. Always use dependency injection:

```php
use AIArmada\Orders\Contracts\OrderServiceInterface;
use AIArmada\Orders\Services\OrderService;

class CheckoutController
{
    public function __construct(
        private OrderServiceInterface $orderService
    ) {}
}
```

## Creating Orders

### Basic Order Creation

```php
$order = $this->orderService->createOrder([
    'currency' => 'MYR',
    'notes' => 'Customer special instructions',
]);
```

### From Cart (Integration)

```php
use AIArmada\Cart\Models\Cart;

$cart = Cart::find($cartId);
$order = $this->orderService->createFromCart($cart, [
    'notes' => 'Optional notes',
]);
```

## Managing Order Items

### Add Items

```php
$this->orderService->addItem($order, [
    'name' => 'Premium Widget',
    'sku' => 'WGT-001',
    'quantity' => 2,
    'unit_price' => 4999, // cents
    'tax_amount' => 300,  // cents
    'discount_amount' => 0,
    'purchasable_id' => $product->id,
    'purchasable_type' => get_class($product),
    'options' => ['color' => 'blue', 'size' => 'large'],
    'metadata' => ['weight' => 500],
]);
```

### Recalculate Totals

After adding or modifying items:

```php
$this->orderService->recalculateTotals($order);
```

## Managing Addresses

```php
$this->orderService->addAddress($order, [
    'type' => 'shipping', // or 'billing'
    'first_name' => 'John',
    'last_name' => 'Doe',
    'line1' => '123 Main Street',
    'line2' => 'Apt 4B',
    'city' => 'Kuala Lumpur',
    'state' => 'Wilayah Persekutuan',
    'postcode' => '50000',
    'country' => 'MY',
    'phone' => '+60123456789',
    'email' => 'john@example.com',
]);
```

## Payment Processing

### Confirm Payment

```php
$this->orderService->confirmPayment(
    $order,
    transactionId: 'txn_abc123',
    gateway: 'stripe',
    amount: 9900, // cents - partial payments supported
);
```

### Process Refund

```php
$this->orderService->processRefund(
    $order,
    amount: 5000, // cents
    reason: 'Customer requested refund',
    transactionId: 'ref_xyz789',
);
```

## Shipping

### Mark as Shipped

```php
$this->orderService->ship(
    $order,
    carrier: 'DHL',
    trackingNumber: 'DHL1234567890',
);
```

### Confirm Delivery

```php
$this->orderService->confirmDelivery($order);
```

## Cancellation

```php
$this->orderService->cancel(
    $order,
    reason: 'Customer requested cancellation',
    canceledBy: auth()->id(),
);
```

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
| `OrderPaid` | Payment was confirmed |
| `OrderShipped` | Order was shipped |
| `OrderDelivered` | Order was delivered |
| `OrderCanceled` | Order was canceled |
| `OrderRefunded` | Refund was processed |

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

## Invoice Generation

```php
use AIArmada\Orders\Actions\GenerateInvoice;

$generator = app(GenerateInvoice::class);

// Get PDF response for download
return $generator->download($order);

// Get PDF content as string
$pdfContent = $generator->generate($order);
```

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
