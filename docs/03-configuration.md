---
title: Configuration
---

# Configuration

All configuration options are defined in `config/orders.php`.

## Database

Configure table names and JSON column types:

```php
'database' => [
    'tables' => [
        'orders' => 'orders',
        'order_items' => 'order_items',
        'order_addresses' => 'order_addresses',
        'order_payments' => 'order_payments',
        'order_refunds' => 'order_refunds',
        'order_notes' => 'order_notes',
    ],
    // Use 'jsonb' for PostgreSQL
    'json_column_type' => env('ORDERS_JSON_COLUMN_TYPE', env('COMMERCE_JSON_COLUMN_TYPE', 'jsonb')),
],
```

## Currency

Set the default currency and decimal precision:

```php
'currency' => [
    'default' => 'MYR',
    'decimal_places' => 2,
],
```

All monetary values are stored in the smallest currency unit (e.g., cents for USD/MYR).

## Multi-tenancy (Owner Scoping)

Configure owner-based data isolation:

```php
'owner' => [
        'enabled' => env('ORDERS_OWNER_ENABLED', false),
        'include_global' => env('ORDERS_OWNER_INCLUDE_GLOBAL', false),
        'auto_assign_on_create' => env('ORDERS_OWNER_AUTO_ASSIGN_ON_CREATE', true),
    ],
```

## Order Number Format

Customize how order numbers are generated:

```php
'order_number' => [
    'prefix' => env('ORDERS_ORDER_NUMBER_PREFIX', 'ORD'),
    'separator' => env('ORDERS_ORDER_NUMBER_SEPARATOR', '-'),
    'length' => env('ORDERS_ORDER_NUMBER_LENGTH', 8),
    'use_date' => env('ORDERS_ORDER_NUMBER_USE_DATE', true),
    'date_format' => env('ORDERS_ORDER_NUMBER_DATE_FORMAT', 'Ymd'),
],
```

Example output: `ORD-20240115-AB12CD34`

## Invoice Settings

Configure invoice number generation:

```php
'invoice' => [
    'prefix' => env('ORDERS_INVOICE_PREFIX', 'INV'),
    'separator' => env('ORDERS_INVOICE_SEPARATOR', '-'),
    'random_length' => env('ORDERS_INVOICE_RANDOM_LENGTH', 6),
    'date_format' => env('ORDERS_INVOICE_DATE_FORMAT', 'Ymd'),
],
```

## Integrations

Enable/disable integrations with other Commerce packages:

```php
'integrations' => [
    'inventory' => [
        'enabled' => true, // Auto-reserve/release inventory
    ],

    'affiliates' => [
        'enabled' => true, // Track affiliate commissions
    ],

    'docs' => [
        'enabled' => env('ORDERS_INTEGRATIONS_DOCS_ENABLED', false),
        'generate_pdf' => env('ORDERS_INTEGRATIONS_DOCS_GENERATE_PDF', false),
    ],
],
```

## Order Status Defaults

Define which order states are allowed as initial values and the default used when no status is provided:

```php
'status' => [
    'allowed' => [
        'created',
        'pending_payment',
        'processing',
    ],
    'default' => 'created',
],
```

Recommended usage:
- **E-commerce flow**: keep `processing` as default (order created after payment).
- **Traditional flow**: set default to `created` or pass an explicit status on create.

The Docs integration is disabled by default. Enable it only when you want `OrderPaid` to auto-create persisted Docs invoices.

## Audit Logging

Configure audit logging behavior:

```php
'audit' => [
    // Enable audit logging
    'enabled' => env('ORDERS_AUDIT_ENABLED', true),
    
    // Minimum order value (cents) to trigger detailed auditing
'threshold' => env('ORDERS_AUDIT_THRESHOLD', 500),
],
```

## Notifications

```php
'notifications' => [
    'payment_confirmation' => [
        'enabled' => env('ORDERS_PAYMENT_CONFIRMATION_ENABLED', true),
        'from_address' => env('ORDERS_PAYMENT_CONFIRMATION_FROM', 'sales@unfairadvantage.my'),
        'from_name' => env('ORDERS_PAYMENT_CONFIRMATION_FROM_NAME'),
        'event_name' => env('ORDERS_PAYMENT_CONFIRMATION_EVENT_NAME', 'AI Awakening'),
    ],
],
```

The payment confirmation notification is sent when an order transitions to paid. It routes to the order's billing address, or the shipping address when billing is missing.

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `ORDERS_JSON_COLUMN_TYPE` | `jsonb` | JSON column type (`json` or `jsonb`) |
| `ORDERS_OWNER_ENABLED` | `false` | Enable multitenancy/owner scoping |
| `ORDERS_OWNER_INCLUDE_GLOBAL` | `false` | Include global records in queries |
| `ORDERS_OWNER_AUTO_ASSIGN_ON_CREATE` | `true` | Auto-assign owner on create |
| `ORDERS_ORDER_NUMBER_PREFIX` | `ORD` | Order number prefix |
| `ORDERS_ORDER_NUMBER_SEPARATOR` | `-` | Order number separator |
| `ORDERS_ORDER_NUMBER_LENGTH` | `8` | Random portion length |
| `ORDERS_ORDER_NUMBER_USE_DATE` | `true` | Include date in order number |
| `ORDERS_ORDER_NUMBER_DATE_FORMAT` | `Ymd` | Date format for order numbers |
| `ORDERS_INVOICE_PREFIX` | `INV` | Invoice number prefix |
| `ORDERS_INTEGRATIONS_DOCS_ENABLED` | `false` | Enable automatic Docs invoice creation on `OrderPaid` |
| `ORDERS_INTEGRATIONS_DOCS_GENERATE_PDF` | `false` | Generate PDFs when Docs invoices are auto-created |
| `ORDERS_AUDIT_ENABLED` | `true` | Enable audit logging |
| `ORDERS_AUDIT_THRESHOLD` | `500` | Audit threshold in cents |
| `ORDERS_PAYMENT_CONFIRMATION_ENABLED` | `true` | Enable payment confirmation emails on `OrderPaid` |
| `ORDERS_PAYMENT_CONFIRMATION_FROM` | `sales@unfairadvantage.my` | Mail sender address for payment confirmations |
| `ORDERS_PAYMENT_CONFIRMATION_FROM_NAME` | `null` | Mail sender name for payment confirmations |
| `ORDERS_PAYMENT_CONFIRMATION_EVENT_NAME` | `AI Awakening` | Event name used in the payment confirmation email |

## Full Configuration Example

```php
<?php

return [
    'database' => [
        'tables' => [
            'orders' => 'orders',
            'order_items' => 'order_items',
            'order_addresses' => 'order_addresses',
            'order_payments' => 'order_payments',
            'order_refunds' => 'order_refunds',
            'order_notes' => 'order_notes',
        ],
'json_column_type' => env('ORDERS_JSON_COLUMN_TYPE', env('COMMERCE_JSON_COLUMN_TYPE', 'jsonb')),
    ],

    'currency' => [
        'default' => 'MYR',
        'decimal_places' => 2,
    ],

    'owner' => [
        'enabled' => env('ORDERS_OWNER_ENABLED', false),
        'include_global' => env('ORDERS_OWNER_INCLUDE_GLOBAL', false),
        'auto_assign_on_create' => env('ORDERS_OWNER_AUTO_ASSIGN_ON_CREATE', true),
    ],

    'status' => [
        'allowed' => [
            'created',
            'pending_payment',
            'processing',
        ],
        'default' => 'created',
    ],

    'order_number' => [
        'prefix' => env('ORDERS_ORDER_NUMBER_PREFIX', 'ORD'),
        'separator' => env('ORDERS_ORDER_NUMBER_SEPARATOR', '-'),
        'length' => env('ORDERS_ORDER_NUMBER_LENGTH', 8),
        'use_date' => env('ORDERS_ORDER_NUMBER_USE_DATE', true),
        'date_format' => env('ORDERS_ORDER_NUMBER_DATE_FORMAT', 'Ymd'),
    ],

    'invoice' => [
        'prefix' => env('ORDERS_INVOICE_PREFIX', 'INV'),
        'separator' => env('ORDERS_INVOICE_SEPARATOR', '-'),
        'random_length' => env('ORDERS_INVOICE_RANDOM_LENGTH', 6),
        'date_format' => env('ORDERS_INVOICE_DATE_FORMAT', 'Ymd'),
    ],

    'integrations' => [
        'inventory' => ['enabled' => true],
        'affiliates' => ['enabled' => true],
        'docs' => [
            'enabled' => env('ORDERS_INTEGRATIONS_DOCS_ENABLED', false),
            'generate_pdf' => env('ORDERS_INTEGRATIONS_DOCS_GENERATE_PDF', false),
        ],
    ],

    'audit' => [
        'enabled' => env('ORDERS_AUDIT_ENABLED', true),
        'threshold' => env('ORDERS_AUDIT_THRESHOLD', 500),
    ],
];
```
