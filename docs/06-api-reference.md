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
| `cancellation_reason` | `string\|null` | Cancellation reason |

#### Relationships

```php
// Items in this order
$order->items(): HasMany<OrderItem>

// Billing address
$order->billingAddress(): HasOne<OrderAddress>

// Shipping address  
$order->shippingAddress(): HasOne<OrderAddress>

// All addresses
$order->addresses(): HasMany<OrderAddress>

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
$order->isFinal(): bool

// Payment helpers
$order->isPaid(): bool
$order->isFullyPaid(): bool
$order->totalPaid(): int        // cents
$order->amountDue(): int        // cents
$order->totalRefunded(): int    // cents

// Formatting
$order->formattedSubtotal(): string       // "MYR 99.00"
$order->formattedGrandTotal(): string     // "MYR 119.00"
$order->formattedShippingTotal(): string  // "MYR 10.00"
$order->formattedTaxTotal(): string       // "MYR 10.00"

// Scopes (static)
Order::forOwner(includeGlobal: false): Builder
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

### OrderAddress

Billing and shipping addresses.

| Property | Type | Description |
|----------|------|-------------|
| `id` | `string` | UUID primary key |
| `order_id` | `string` | Parent order ID |
| `type` | `string` | `billing` or `shipping` |
| `first_name` | `string\|null` | First name |
| `last_name` | `string\|null` | Last name |
| `company` | `string\|null` | Company name |
| `line1` | `string` | Address line 1 |
| `line2` | `string\|null` | Address line 2 |
| `city` | `string` | City |
| `state` | `string\|null` | State/province |
| `postcode` | `string\|null` | Postal/ZIP code |
| `country` | `string` | ISO country code |
| `phone` | `string\|null` | Phone number |
| `email` | `string\|null` | Email address |
| `metadata` | `array\|null` | Additional metadata |

#### Methods

```php
// Get full name
$address->fullName(): string  // "John Doe"

// Get formatted single-line address
$address->formattedAddress(): string
// "123 Main St, Kuala Lumpur, WP 50000, MY"

// Get formatted multi-line address
$address->formattedAddressMultiLine(): string

// Scopes
OrderAddress::billing(): Builder
OrderAddress::shipping(): Builder
```

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
| `status` | `PaymentStatus` | Enum status |
| `refunded_at` | `Carbon\|null` | Refund timestamp |
| `metadata` | `array\|null` | Additional data |

### OrderNote

Order notes.

| Property | Type | Description |
|----------|------|-------------|
| `id` | `string` | UUID primary key |
| `order_id` | `string` | Parent order ID |
| `user_id` | `string\|null` | Author user ID |
| `content` | `text` | Note content |
| `is_customer_visible` | `bool` | Visible to customer |

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

## Contracts

### OrderServiceInterface

```php
interface OrderServiceInterface
{
    public function createOrder(array $data): Order;
    public function createFromCart(Cart $cart, array $data = []): Order;
    public function addItem(Order $order, array $data): OrderItem;
    public function addAddress(Order $order, array $data): OrderAddress;
    public function cancel(Order $order, string $reason, ?string $canceledBy = null): Order;
    public function confirmPayment(Order $order, string $transactionId, string $gateway, int $amount): Order;
    public function ship(Order $order, string $carrier, string $trackingNumber): Order;
    public function confirmDelivery(Order $order): Order;
    public function processRefund(Order $order, int $amount, string $reason, ?string $transactionId = null): Order;
    public function recalculateTotals(Order $order): Order;
}
```

### FulfillmentHandler

```php
interface FulfillmentHandler
{
    /**
     * @param Order $order
     * @param string $carrier
     * @param array<string, mixed> $options
     * @return array{success: bool, shipment_id: ?string, tracking_number: ?string, error: ?string}
     */
    public function createShipment(Order $order, string $carrier, array $options = []): array;
    public function getTrackingUrl(Order $order): ?string;
    public function cancelShipment(Order $order): bool;
}
```

### InventoryHandler

```php
interface InventoryHandler
{
    public function reserveStock(Order $order): bool;
    public function releaseStock(Order $order): bool;
    public function commitStock(Order $order): bool;
}
```

### PaymentHandler

```php
interface PaymentHandler
{
    /**
     * @return array{success: bool, transaction_id: ?string, error: ?string}
     */
    public function processPayment(Order $order, int $amount, string $gateway): array;
    
    /**
     * @return array{success: bool, transaction_id: ?string, error: ?string}
     */
    public function processRefund(Order $order, int $amount, string $reason): array;
}
```

## Events

| Event | Properties |
|-------|------------|
| `OrderCreated` | `Order $order` |
| `OrderPaid` | `Order $order`, `string $transactionId`, `string $gateway` |
| `OrderShipped` | `Order $order`, `string $carrier`, `string $trackingNumber`, `?string $shipmentId` |
| `OrderDelivered` | `Order $order` |
| `OrderCanceled` | `Order $order`, `string $reason`, `?string $canceledBy` |
| `OrderRefunded` | `Order $order`, `int $amount`, `string $reason` |
