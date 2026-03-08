---
title: Overview
---

# Orders Package

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
    ├── Enums/              # PaymentStatus
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
- Laravel 12+
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
