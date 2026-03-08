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
    'json_column_type' => env('ORDERS_JSON_COLUMN_TYPE', 'json'),
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
    // Enable owner scoping
    'enabled' => true,
    
    // Include global (owner=null) records in queries
    'include_global' => false,
    
    // Auto-assign current owner on order creation
    'auto_assign_on_create' => true,
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

## Order Status Defaults

Define which order states are allowed as initial values and the default used when no status is provided:

```php
'status' => [
    'allowed' => [
        'created',
        'pending_payment',
        'processing',
    ],
    'default' => 'processing',
],
```

Recommended usage:
- **E-commerce flow**: keep `processing` as default (order created after payment).
- **Traditional flow**: set default to `created` or pass an explicit status on create.
    'affiliates' => [
        'enabled' => true, // Track affiliate commissions
    ],
],
```

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

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `ORDERS_JSON_COLUMN_TYPE` | `json` | JSON column type (`json` or `jsonb`) |
| `ORDERS_ORDER_NUMBER_PREFIX` | `ORD` | Order number prefix |
| `ORDERS_ORDER_NUMBER_SEPARATOR` | `-` | Order number separator |
| `ORDERS_ORDER_NUMBER_LENGTH` | `8` | Random portion length |
| `ORDERS_ORDER_NUMBER_USE_DATE` | `true` | Include date in order number |
| `ORDERS_ORDER_NUMBER_DATE_FORMAT` | `Ymd` | Date format for order numbers |
| `ORDERS_INVOICE_PREFIX` | `INV` | Invoice number prefix |
| `ORDERS_AUDIT_ENABLED` | `true` | Enable audit logging |
| `ORDERS_AUDIT_THRESHOLD` | `500` | Audit threshold in cents |

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
        'json_column_type' => env('ORDERS_JSON_COLUMN_TYPE', 'json'),
    ],

    'currency' => [
        'default' => 'MYR',
        'decimal_places' => 2,
    ],

    'owner' => [
        'enabled' => true,
        'include_global' => false,
        'auto_assign_on_create' => true,
    ],

    'status' => [
        'allowed' => [
            'created',
            'pending_payment',
            'processing',
        ],
        'default' => 'processing',
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
    ],

    'audit' => [
        'enabled' => env('ORDERS_AUDIT_ENABLED', true),
        'threshold' => env('ORDERS_AUDIT_THRESHOLD', 500),
    ],
];
```
