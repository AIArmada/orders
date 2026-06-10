---
title: Overview
---

# Orders Package

## Purpose

The `aiarmada/orders` package owns order records, order lifecycle transitions, payment and refund tracking, addresses, notes, and invoice generation for the Commerce ecosystem.

## What this package owns

- Orders, order items, addresses, payments, refunds, and notes
- Order state transitions and transaction lifecycle workflows
- Invoice generation, audit logging, and order-focused health checks
- Integration contracts for inventory, payment, and fulfilment handlers

## What this package does not own

- Cart persistence or checkout orchestration
- Shipping-carrier execution or rate shopping; those belong to `aiarmada/shipping` and carrier adapters such as `aiarmada/jnt`
- Filament admin surfaces; those belong to `aiarmada/filament-orders`

## Related packages

- [`aiarmada/checkout`](../../checkout/docs/01-overview.md) — orchestration layer that creates orders
- [`aiarmada/filament-orders`](../../filament-orders/docs/01-overview.md) — Filament admin resources and widgets for orders
- [`aiarmada/shipping`](../../shipping/docs/01-overview.md) — fulfilment and shipment integration
- [`aiarmada/docs`](../../docs/docs/01-overview.md) — document generation and order-related output surfaces
- [`aiarmada/commerce-support`](../../commerce-support/docs/01-overview.md) — owner scoping and shared primitives

## Main models services or surfaces

- **Models** — order, order items, addresses, payments, refunds, and notes
- **Services and actions** — `OrderService`, invoice generation, payment confirmation, shipping transitions, refunds, and total recalculation
- **State machine** — order state classes and transitions

## Owner scoping and security notes

- Orders are owner-aware and should follow `commerce-support` owner-boundary rules
- Payment, refund, address, and fulfilment actions should resolve target orders inside the current owner scope before mutating state
- Invoice generation and download routes should remain owner-safe on non-UI entry points too

The Orders package provides a complete order management system for e-commerce applications. It handles order creation, state management, payments, refunds, shipping, and integrates seamlessly with other Commerce packages.

## Features

- **State Machine**: 13 order states with configurable transitions using `spatie/laravel-model-states`
- **Multi-tenancy**: Full owner scoping support via `HasOwner` trait
- **Payment Tracking**: Record payments, refunds, and payment status tracking
- **Address Management**: Billing and shipping address support
- **Order Notes**: Internal and customer-visible notes
- **Invoice Generation**: PDF invoice generation via `spatie/laravel-pdf`
- **Health Checks**: Monitor order processing health
- **Auditing**: Automatic audit logging for compliance
- **Integration Ready**: Contracts for inventory, payment, and fulfillment handlers

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                         Order                                │
│  ┌───────────┐  ┌───────────┐  ┌───────────┐  ┌───────────┐ │
│  │OrderItems │  │ Addresses │  │ Payments  │  │  Refunds  │ │
│  └───────────┘  └───────────┘  └───────────┘  └───────────┘ │
│  ┌───────────┐                                               │
│  │   Notes   │                                               │
│  └───────────┘                                               │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                       OrderService                           │
│  - createOrder()      - confirmPayment()                     │
│  - addItem()          - ship()                               │
│  - cancel()           - confirmDelivery()                    │
│  - processRefund()    - recalculateTotals()                  │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                   State Machine (13 States)                  │
│  Created → PendingPayment → Processing → Shipped            │
│  → Delivered → Completed                                     │
│  + Canceled, Refunded, Returned, OnHold, Fraud, PaymentFailed│
└─────────────────────────────────────────────────────────────┘
```

## Package Structure

```
packages/orders/
├── config/
│   └── orders.php           # Configuration
├── database/
│   └── migrations/          # Database migrations
├── docs/                    # Documentation
├── resources/
│   ├── lang/               # Translations
│   └── views/              # Invoice templates
└── src/
    ├── Actions/            # GenerateInvoice
    ├── Contracts/          # Service & Handler interfaces
    ├── Enums/              # PaymentStatus, RefundStatus, OrderItemStatus
    ├── Events/             # Order lifecycle events
    ├── Health/             # Health checks
    ├── Models/             # Eloquent models
    ├── Policies/           # Authorization policies
    ├── Services/           # OrderService
    ├── States/             # Order state classes
    └── Transitions/        # State transition logic
```

## Requirements

- PHP 8.4+
- Laravel 13+
- `spatie/laravel-model-states` ^2.0
- `spatie/laravel-pdf` ^1.0 (for invoices)
- `aiarmada/commerce-support` (for multi-tenancy)

## Quick Start

```php
use AIArmada\Orders\Services\OrderService;

// Create an order
$order = app(OrderService::class)->createOrder([
    'currency' => 'MYR',
    'notes' => 'Customer notes',
]);

// Add items
app(OrderService::class)->addItem($order, [
    'name' => 'Product Name',
    'sku' => 'SKU-001',
    'quantity' => 2,
    'unit_price' => 9900, // cents
]);

// Confirm payment
app(OrderService::class)->confirmPayment(
    $order,
    'txn_123456',
    'stripe',
    9900 // cents
);

// Ship the order
app(OrderService::class)->ship($order, 'DHL', 'TRACK123');

// Confirm delivery
app(OrderService::class)->confirmDelivery($order);
```

## Read next

- [Installation](02-installation.md)
- [Configuration](03-configuration.md)
- [Usage](04-usage.md)
- [State machine](05-state-machine.md)
- [API reference](06-api-reference.md)
- [Troubleshooting](99-troubleshooting.md)
- [Filament Orders overview](../../filament-orders/docs/01-overview.md)
