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

// Basic creation — $orderData and $items are both required
$order = app(CreateOrder::class)->execute(
    orderData: [
        'currency' => 'MYR',
        'notes' => 'Customer special instructions',
    ],
    items: [
        [
            'name' => 'Product Name',
            'sku' => 'SKU-001',
            'quantity' => 2,
            'unit_price' => 9900, // cents
        ],
    ],
);

// From cart — the second argument is the customer Eloquent model
$cart = app(\AIArmada\Cart\Contracts\CartManagerInterface::class)->getCurrentCart();

$order = app(CreateOrderFromCart::class)->execute(
    cart: $cart,
    customer: $customer,
    intakeSource: 'checkout',
    intakeId: $sessionId,
);
```

### Durable Intake Identity

Prevent duplicate orders from retries and concurrent submissions using intake identity. Pass `intakeSource` and `intakeId` to make order creation idempotent:

```php
use AIArmada\Orders\Actions\CreateOrder;

// Idempotent creation — same intake identity returns the existing order
$order = app(CreateOrder::class)->execute(
    orderData: [
        'currency' => 'MYR',
        'subtotal' => 5000,
        'grand_total' => 5000,
    ],
    items: $items,
    intakeSource: 'checkout',
    intakeId: 'sess_abc123',
);

// Exact retry — returns the same order, no duplicate
$retry = app(CreateOrder::class)->execute(
    orderData: [
        'currency' => 'MYR',
        'subtotal' => 5000,
        'grand_total' => 5000,
    ],
    items: $items,
    intakeSource: 'checkout',
    intakeId: 'sess_abc123',
);

assert($retry->id === $order->id); // Same order
```

**Intake identity guarantees:**

- **Same intake (source + id) returns the existing order.** Items, addresses, and relationships are loaded on the returned model.
- **Database-level unique constraint** on `(owner_type, owner_id, intake_source, intake_id)` prevents concurrent duplicates.
- **Without intake identity,** each call creates a new order.
- **Different source with same id** creates separate orders (e.g., `checkout` vs `api` with same session id).

The Checkout `CreateOrderStep` uses `intakeSource: 'checkout'` with the session id as `intakeId`.

### Payment & Refunds

```php
use AIArmada\Orders\Actions\RegisterOrderPayment;
use AIArmada\Orders\Actions\RegisterOrderRefund;

// Confirm payment
app(RegisterOrderPayment::class)->execute(
    order: $order,
    transactionId: 'txn_abc123',
    gateway: 'stripe',
    amount: 9900, // cents - partial payments supported
);

// Process refund
app(RegisterOrderRefund::class)->execute(
    order: $order,
    amount: 5000, // cents
    transactionId: 'ref_xyz789',
    reason: 'Customer requested refund',
);
```

> **info**
> `RegisterOrderRefund::execute()` declares `amount`, then `transactionId`, then
> `reason`. Keep that order or use the named arguments shown above.

### Cancellation & Completion

```php
use AIArmada\Orders\Actions\CancelOrder;
use AIArmada\Orders\Actions\CompleteOrder;

// Cancel
app(CancelOrder::class)->execute(
    order: $order,
    reason: 'Customer requested cancellation',
    canceledBy: (string) auth()->id(),
);

// Complete (marks as completed)
app(CompleteOrder::class)->execute($order);
```

### Deleting Orders (Prefer Cancel/Refund)

Prefer `CancelOrder` / `RegisterOrderRefund` over deleting. `Order::delete()` refuses paid or final orders with a `LogicException`; pass `delete(force: true)` only for an explicit administrative retention override. Deletes run in a transaction that removes items, payments, refunds, and notes and detaches addresses.

```php
use AIArmada\Orders\Models\Order;

$order->delete(); // throws on paid/final orders
$order->delete(force: true); // admin retention override only
```

## OrderService (Compatibility)

The `OrderServiceInterface` is a public service contract that delegates to Actions:

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
| `cancel()` | `OrderCanceled` transition |
| `confirmPayment()` | `RegisterOrderPayment` |
| `confirmFreeOrder()` | `FreeOrderConfirmed` transition |
| `processRefund()` | `RegisterOrderRefund` |
| `ship()` | `ShipmentCreated` transition |
| `confirmDelivery()` | `DeliveryConfirmed` transition |
| `complete()` | `OrderCompleted` transition |

### Confirming free orders

```php
use AIArmada\Orders\Contracts\OrderServiceInterface;

$order = $orderService->confirmFreeOrder($order); // Created/PendingPayment → Processing
```

Use this only for orders with `grand_total <= 0` and `paid_total === 0`. Paid, partially paid, and balance-owing Processing orders are rejected with `InvalidArgumentException`; held, canceled, or failed orders throw the `OrderNotAwaitingPayment` subclass. On success the order moves to Processing and stock deduction is scheduled after commit; no payment record, `paid_at`, or `OrderPaid` event is produced. See the [state machine](05-state-machine.md) for the full contract and known limitations.

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
    ->with(['items', 'payments', 'refunds', 'orderNotes', 'addresses'])
    ->findOrFail($orderId);
```

`Order` has no `billingAddress` / `shippingAddress` relations. Use the
`addresses` relation plus `primaryAddress('billing')` / `primaryAddress('shipping')`
from the `HasAddresses` trait, or `addressesOfType('billing')`.

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
echo $order->getFormattedSubtotal();    // "MYR 99.00"
echo $order->getFormattedGrandTotal();  // "MYR 119.00"

// Check payment status
if ($order->isPaid()) {
    // Order has been paid
}

if ($order->isFullyPaid()) {
    // Total payments >= grand total
}
```

### Cached payment/refund totals

`paid_total`, `refunded_total`, and `pending_refunded_total` are cached on the order so balance checks (`getTotalPaid()`, `getTotalRefunded()`, `getRemainingRefundable()`, `getBalanceDue()`, `isFullyPaid()`) never fan out into per-relation sums. The caches are the single source of truth for reads and are kept in sync by `OrderPayment`/`OrderRefund` model events — every write path (transitions, actions, and direct creates) flows through them, so do not assign these columns manually. Item totals still come from `recalculateTotals()`, which uses ex-tax subtotals: `grand_total = subtotal + tax_total + shipping_total - discount_total`.

## Fulfillment & Addresses

Orders are carrier-agnostic: pass an explicit carrier string to `ship()` — no carrier is hardcoded. `ship()` runs the `ShipmentCreated` transition, which records the carrier and tracking number in order metadata. Carrier API operations go through the `AIArmada\Orders\Contracts\FulfillmentHandler` contract, which the shipping package binds in the container when installed.

```php
use AIArmada\Orders\Services\OrderService;

app(OrderService::class)->ship($order, 'DHL', 'TRACK123');
```

Addresses are normalized through the canonical `AIArmada\Addressing\Actions\NormalizeAddressDataAction`; contact fields stay in address metadata. Each `addAddress()` call attaches one fresh `Address` copy per order and type, and additionally writes an immutable `AddressSnapshot` (`order_billing` / `order_shipping`) when `orders.address_snapshots.enabled` is set. `status` is always a Spatie `AIArmada\Orders\States\OrderStatus` instance (see `05-state-machine.md`).

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
| `InventoryDeductionRequired` | Inventory deduction needed |
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
        $amount = $event->amount;

        // Send confirmation email
    }
}
```

## Outbox (Relay & Sweep)

`PaymentConfirmed` and `FreeOrderConfirmed` stage one outbox row per replayable event in the same transaction as the state change. The live after-commit dispatch marks rows relayed; anything it misses (crash between commit and dispatch) is recovered by the relay. Delivery is at-least-once, so every consumer of the replayable events is idempotent: inventory deduction, pass issuance, event registration sync, promotion usage counting, and commission attribution.

Run the relay frequently (every minute) and the sweep less often (hourly):

```php
// routes/console.php
use AIArmada\Orders\Actions\Outbox\RelayOrderOutbox;
use AIArmada\Orders\Actions\Outbox\SweepOrderOutbox;

Schedule::command(RelayOrderOutbox::class)->everyMinute();
Schedule::command(SweepOrderOutbox::class)->hourly();
```

Or run them directly:

```bash
php artisan orders:outbox-relay --limit=100
php artisan orders:outbox-sweep
```

Both entrypoints are [Laravel Actions](https://www.laravelactions.com/): call `RelayOrderOutbox::run()` / `SweepOrderOutbox::run()` from code to get result counts, or run the artisan signatures above (same class, command entrypoint).

The sweep requeues rows stuck in `relaying` past the claim timeout, purges `relayed` history past retention, and reports `dead` rows. Dead rows need operator review — the sweep never repairs or invents rows.

Lifecycle suppression: cancelling an order, completing a full refund, or flagging it as fraud terminally suppresses that order's unrelayed rows in the same transaction (`suppressed`, with the reason in `last_error`), so replay can never fulfill after cancel/refund cleanup ran or while an order is under investigation. Partial refunds leave staged rows untouched. The relay additionally re-validates the order lifecycle under a row lock after claiming each row and holds that lock through dispatch, so a cancel landing mid-relay either suppresses first or cleans up after — replay can never overtake cleanup. Suppressed rows are terminal and need no operator review.

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

### Shared Builder: `BuildsOrderDocs`

`CreateOrderInvoiceDoc`, `CreateOrderReceiptDoc`, `GenerateInvoice`, and `GenerateReceipt` all share the `AIArmada\Orders\Actions\Concerns\BuildsOrderDocs` trait. Persist actions write `Docs` rows through that builder; generators are render-only — they return a PDF download (or HTML fallback without a PDF runtime) and never create `Docs` records.

### PDF Invoice Output

```php
use AIArmada\Orders\Actions\GenerateInvoice;

$generator = app(GenerateInvoice::class);

// Get a download response (PDF when a PDF runtime is present, HTML fallback otherwise)
return $generator->download($order);

// Or write the rendered document to a path and get the path back
$path = $generator->save($order, storage_path('app/invoices/'.$order->order_number.'.pdf'));
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
