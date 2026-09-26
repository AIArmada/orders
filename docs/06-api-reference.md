---
title: API Reference
---

# API Reference

## Models

### Order

The main order model.

#### Properties

| Property | Type | Description |
|----------|------|-------------|
| `id` | `string` | UUID primary key |
| `order_number` | `string` | Unique order number |
| `status` | `OrderStatus` | Current state (model-states) |
| `customer_id` | `string\|null` | Polymorphic customer ID |
| `customer_type` | `string\|null` | Polymorphic customer type |
| `owner_id` | `string\|null` | Polymorphic owner ID (tenant) |
| `owner_type` | `string\|null` | Polymorphic owner type |
| `subtotal` | `int` | Subtotal in cents |
| `discount_total` | `int` | Total discounts in cents |
| `shipping_total` | `int` | Shipping cost in cents |
| `tax_total` | `int` | Tax amount in cents |
| `grand_total` | `int` | Grand total in cents |
| `currency` | `string` | 3-letter currency code |
| `notes` | `string\|null` | Customer-facing notes |
| `internal_notes` | `string\|null` | Internal notes |
| `metadata` | `array\|null` | JSON metadata |
| `paid_at` | `Carbon\|null` | Payment timestamp |
| `shipped_at` | `Carbon\|null` | Shipment timestamp |
| `delivered_at` | `Carbon\|null` | Delivery timestamp |
| `canceled_at` | `Carbon\|null` | Cancellation timestamp |
| `payment_failed_at` | `Carbon\|null` | Payment failure timestamp |
| `held_at` | `Carbon\|null` | Hold timestamp (toggle: set on hold, cleared on release) |
| `flagged_at` | `Carbon\|null` | Fraud flag timestamp (terminal, set once) |
| `returned_at` | `Carbon\|null` | Return timestamp (historical fact) |
| `refunded_at` | `Carbon\|null` | Refund timestamp |
| `completed_at` | `Carbon\|null` | Completion timestamp |
| `cancellation_reason` | `string\|null` | Cancellation reason |

#### Relationships

```php
// Items in this order
$order->items(): HasMany<OrderItem>

// Attached addresses (addressing package; one fresh copy per order and type)
$order->addresses(): MorphToMany<Address>
$order->primaryAddress('billing'): ?Address
$order->primaryAddress('shipping'): ?Address
$order->addressesOfType('shipping'): Collection<int, Address>

// Payment records
$order->payments(): HasMany<OrderPayment>

// Refund records
$order->refunds(): HasMany<OrderRefund>

// Notes
$order->orderNotes(): HasMany<OrderNote>

// Customer (polymorphic)
$order->customer(): MorphTo
```

#### Methods

```php
// State helpers
$order->canBeCanceled(): bool
$order->canBeRefunded(): bool
$order->canBeModified(): bool
$order->isFinal(): bool
$order->isOnHold(): bool
$order->isFlaggedAsFraud(): bool
$order->isReturned(): bool
$order->isShipped(): bool
$order->isDelivered(): bool
$order->isCanceled(): bool

// Payment helpers
$order->isPaid(): bool
$order->isFullyPaid(): bool
$order->getTotalPaid(): int            // cents
$order->getTotalPendingRefunded(): int // cents
$order->getTotalRefunded(): int        // cents
$order->getBalanceDue(): int           // cents
$order->getRemainingRefundable(): int  // cents

// Formatting — MoneyFormatter::prefixSymbol() concatenates without a space
$order->getFormattedSubtotal(): string       // "RM99.00"
$order->getFormattedDiscountTotal(): string  // "RM0.00"
$order->getFormattedShippingTotal(): string  // "RM10.00"
$order->getFormattedTaxTotal(): string       // "RM10.00"
$order->getFormattedGrandTotal(): string     // "RM119.00"

// Scopes (static)
Order::forOwner(includeGlobal: false): Builder
Order::forOwner($owner, includeGlobal: true): Builder
```

### OrderItem

Line items for orders.

| Property | Type | Description |
|----------|------|-------------|
| `id` | `string` | UUID primary key |
| `order_id` | `string` | Parent order ID |
| `purchasable_id` | `string\|null` | Polymorphic product ID |
| `purchasable_type` | `string\|null` | Polymorphic product type |
| `name` | `string` | Product name |
| `sku` | `string\|null` | SKU code |
| `quantity` | `int` | Quantity |
| `unit_price` | `int` | Unit price in cents |
| `discount_amount` | `int` | Discount in cents |
| `tax_amount` | `int` | Tax in cents |
| `total` | `int` | Line total in cents |
| `currency` | `string` | Currency code |
| `status` | `OrderItemStatus` | Item lifecycle status |
| `shipped_at` | `Carbon\|null` | Item shipped timestamp |
| `delivered_at` | `Carbon\|null` | Item delivered timestamp |
| `returned_at` | `Carbon\|null` | Item returned timestamp |
| `canceled_at` | `Carbon\|null` | Item canceled timestamp |
| `options` | `array\|null` | Product options |
| `metadata` | `array\|null` | Additional metadata |

#### Methods

```php
// Calculate line total
$item->calculateTotal(): int  // (quantity × unit_price) - discount + tax

// Relationships
$item->order(): BelongsTo<Order>
$item->purchasable(): MorphTo
```

### Order addresses

Orders carry no local address model. `CreateOrder::addAddress()` (via
`OrderServiceInterface`) stores one fresh addressing `Address` copy per order
and type, with contact fields under `metadata.order_contact`. Resolve them
through `HasAddresses`: `primaryAddress('billing')`,
`primaryAddress('shipping')`, `addressesOfType($type)`. When
`orders.address_snapshots.enabled` is set, each call also writes an immutable
`AddressSnapshot` with reason `order_billing` or `order_shipping`.

### OrderPayment

Payment records.

| Property | Type | Description |
|----------|------|-------------|
| `id` | `string` | UUID primary key |
| `order_id` | `string` | Parent order ID |
| `gateway` | `string` | Payment gateway name |
| `transaction_id` | `string` | Gateway transaction ID |
| `amount` | `int` | Amount in cents |
| `currency` | `string` | Currency code |
| `status` | `PaymentStatus` | Enum status |
| `paid_at` | `Carbon\|null` | Payment timestamp |
| `failed_at` | `Carbon\|null` | Payment failure timestamp |
| `refunded_at` | `Carbon\|null` | Payment refund timestamp |
| `metadata` | `array\|null` | Gateway response data |

### OrderRefund

Refund records.

| Property | Type | Description |
|----------|------|-------------|
| `id` | `string` | UUID primary key |
| `order_id` | `string` | Parent order ID |
| `payment_id` | `string\|null` | Related payment ID |
| `gateway` | `string\|null` | Refund gateway |
| `transaction_id` | `string\|null` | Refund transaction ID |
| `amount` | `int` | Refund amount in cents |
| `currency` | `string` | Currency code |
| `reason` | `string\|null` | Refund reason |
| `status` | `RefundStatus` | Enum status |
| `refunded_at` | `Carbon\|null` | Refund timestamp |
| `failed_at` | `Carbon\|null` | Refund failure timestamp |
| `provider_submission_started_at` | `Carbon\|null` | Timestamp at which an external provider submission was claimed |
| `metadata` | `array\|null` | Additional data |

### OrderNote

Order notes.

| Property | Type | Description |
|----------|------|-------------|
| `id` | `string` | UUID primary key |
| `order_id` | `string` | Parent order ID |
| `user_id` | `string\|null` | Author user ID |
| `content` | `text` | Note content |
| `visibility` | `string` | Visibility: `internal` or `customer` |

#### Scopes

```php
OrderNote::customerVisible(): Builder
OrderNote::internal(): Builder
```

## Enums

### PaymentStatus

```php
use AIArmada\Orders\Enums\PaymentStatus;

PaymentStatus::Pending    // Awaiting processing
PaymentStatus::Completed  // Successfully completed
PaymentStatus::Failed     // Payment failed
PaymentStatus::Refunded   // Fully refunded

// Methods
$status->label(): string  // "Completed"
$status->color(): string  // "success"
$status->isFinal(): bool  // true for Completed/Failed/Refunded
```

### RefundStatus

```php
use AIArmada\Orders\Enums\RefundStatus;

RefundStatus::Pending    // Awaiting processing
RefundStatus::Completed  // Successfully completed
RefundStatus::Failed     // Refund failed

// Methods
$status->label(): string  // "Completed"
$status->color(): string  // "success"
$status->isFinal(): bool  // true for Completed/Failed
```

### OrderItemStatus

```php
use AIArmada\Orders\Enums\OrderItemStatus;

OrderItemStatus::Active       // Item is active
OrderItemStatus::Shipped      // Item has been shipped
OrderItemStatus::Delivered    // Item has been delivered
OrderItemStatus::Returned     // Item has been returned
OrderItemStatus::Canceled     // Item has been canceled
OrderItemStatus::Backordered  // Item is backordered

// Methods
$status->label(): string  // "Shipped"
$status->color(): string  // "info"
$status->isFinal(): bool  // true for Delivered/Returned/Canceled
```

## Contracts

### OrderServiceInterface

```php
interface OrderServiceInterface
{
    public function createOrder(
        array $orderData,
        array $items,
        ?array $billingAddress = null,
        ?array $shippingAddress = null,
        ?string $intakeSource = null,
        ?string $intakeId = null,
    ): Order;

    public function createFromCart(
        Cart|CartManagerInterface $cart,
        Model $customer,
        ?array $billingAddress = null,
        ?array $shippingAddress = null,
        ?string $intakeSource = null,
        ?string $intakeId = null,
        ?string $sessionId = null,
    ): Order;

    public function addItem(Order $order, array $itemData): OrderItem;
    public function addAddress(Order $order, array $addressData, string $type): void;
    public function cancel(Order $order, string $reason, ?string $canceledBy = null): Order;

    public function confirmPayment(
        Order $order,
        string $transactionId,
        string $gateway,
        int $amount,
        array $metadata = [],
    ): Order;

    public function ship(
        Order $order,
        string $carrier,
        string $trackingNumber,
        ?string $shipmentId = null,
        array $metadata = [],
    ): Order;

    public function confirmDelivery(Order $order, array $metadata = []): Order;
    public function complete(Order $order, array $metadata = []): Order;

    // transactionId is the 3rd argument, reason the 4th — both required
    public function processRefund(
        Order $order,
        int $amount,
        string $transactionId,
        string $reason,
        array $metadata = [],
    ): Order;

    public function createPendingRefund(
        Order $order,
        int $amount,
        string $transactionId,
        string $reason,
        array $metadata = [],
    ): OrderRefund;

    public function claimPendingRefundSubmission(OrderRefund $refund): bool;
    public function completePendingRefund(OrderRefund $refund, ?string $transactionId = null): Order;
    public function failPendingRefund(OrderRefund $refund, string $reason): OrderRefund;

    public function recalculateTotals(Order $order): Order;
}
```

### FulfillmentHandler

```php
interface FulfillmentHandler
{
    /**
     * @return array<string, string>
     */
    public function availableCarriers(): array;

    /**
     * @param  array<string, mixed>  $shipmentData  Carrier, service, etc.
     * @return array{success: bool, shipment_id: ?string, tracking_number: ?string, error: ?string}
     */
    public function createShipment(Order $order, array $shipmentData): array;

    /**
     * @return array<array{carrier: string, service: string, rate: int, currency: string}>
     */
    public function getRates(Order $order): array;

    /**
     * @return array{status: string, events: array<array{date: string, description: string, location: ?string}>}
     */
    public function getTracking(string $trackingNumber): array;
}
```

> **info**
> There is no `getTrackingUrl()`, `cancelShipment()`, or `CarrierOperationResult` on this contract. `AIArmada\Shipping\Integrations\OrderFulfillmentHandler` is the shipped implementation.

### InventoryHandler

```php
interface InventoryHandler
{
    public function reserveInventory(Order $order): bool;
    public function deductInventory(Order $order): bool;
    public function releaseInventory(Order $order): bool;
    public function checkAvailability(Order $order): bool;
}
```

There is no `reserveStock()`, `releaseStock()`, or `commitStock()`.

### PaymentHandler

```php
interface PaymentHandler
{
    /**
     * @param  array<string, mixed>  $paymentData  Payment method data
     * @return array{success: bool, transaction_id: ?string, error: ?string}
     */
    public function processPayment(Order $order, array $paymentData): array;

    /**
     * @return array{success: bool, transaction_id: ?string, error: ?string}
     */
    public function processRefund(Order $order, int $amount, string $reason): array;

    /**
     * @return array<string, array{name: string, icon: ?string}>
     */
    public function getPaymentMethods(): array;
}
```

## Events

| Event | Properties |
|-------|------------|
| `OrderCreated` | `Order $order` |
| `OrderPaid` | `Order $order`, `string $transactionId`, `string $gateway` |
| `OrderProcessingStarted` | `Order $order`, `string $transactionId`, `string $gateway` |
| `OrderShipped` | `Order $order`, `string $carrier`, `string $trackingNumber`, `?string $shipmentId` |
| `OrderDelivered` | `Order $order` |
| `OrderCompleted` | `Order $order` |
| `OrderCanceled` | `Order $order`, `string $reason`, `?string $canceledBy` |
| `OrderCancelInitiated` | `Order $order`, `string $reason`, `?string $canceledBy` |
| `OrderHeld` | `Order $order`, `string $reason`, `?string $heldBy` |
| `OrderHoldReleased` | `Order $order`, `?string $reason`, `?string $releasedBy` |
| `OrderFlaggedAsFraud` | `Order $order`, `string $reason`, `?string $flaggedBy` |
| `OrderReturned` | `Order $order`, `?string $reason`, `?string $returnedBy` |
| `OrderRefunded` | `Order $order`, `int $amount`, `string $reason`, `array $metadata` |
| `OrderRefundFailed` | `Order $order`, `OrderRefund $refund`, `string $reason`, `array $metadata` |
| `OrderPaymentFailed` | `Order $order`, `string $reason` |
