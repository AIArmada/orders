<?php

declare(strict_types=1);

return [
    /* Database */
    'database' => [
        'json_column_type' => env('ORDERS_JSON_COLUMN_TYPE', 'jsonb'),
        'tables' => [
            'orders' => 'orders',
            'order_items' => 'order_items',
            'order_payments' => 'order_payments',
            'order_refunds' => 'order_refunds',
            'order_notes' => 'order_notes',
            'order_outbox' => 'order_outbox_messages',
        ],
    ],

    /* Defaults */
    'currency' => [
        'default' => 'MYR',
        'decimal_places' => 2,
    ],

    /* Invoice company details */
    'company' => [
        'address' => env('ORDERS_COMPANY_ADDRESS', ''),
        'phone' => env('ORDERS_COMPANY_PHONE', ''),
        'email' => env('ORDERS_COMPANY_EMAIL', ''),
    ],

    /* Features */
    'owner' => [
        'enabled' => env('ORDERS_OWNER_ENABLED', false),
        'include_global' => env('ORDERS_OWNER_INCLUDE_GLOBAL', false),
        'auto_assign_on_create' => env('ORDERS_OWNER_AUTO_ASSIGN_ON_CREATE', true),
    ],

    'address_snapshots' => [
        'enabled' => env('ORDERS_ADDRESS_SNAPSHOTS_ENABLED', false),
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

    'outbox' => [
        'enabled' => env('ORDERS_OUTBOX_ENABLED', true),
        'relay_grace_seconds' => env('ORDERS_OUTBOX_RELAY_GRACE_SECONDS', 60),
        'batch_limit' => env('ORDERS_OUTBOX_BATCH_LIMIT', 100),
        'max_attempts' => env('ORDERS_OUTBOX_MAX_ATTEMPTS', 10),
        'retry_base_seconds' => env('ORDERS_OUTBOX_RETRY_BASE_SECONDS', 60),
        'retry_max_seconds' => env('ORDERS_OUTBOX_RETRY_MAX_SECONDS', 3600),
        'claim_timeout_seconds' => env('ORDERS_OUTBOX_CLAIM_TIMEOUT_SECONDS', 600),
        'retention_days' => env('ORDERS_OUTBOX_RETENTION_DAYS', 30),
    ],

    /* Integrations */
    'integrations' => [
        'inventory' => [
            'enabled' => env('ORDERS_INTEGRATIONS_INVENTORY_ENABLED', true),
        ],
        'affiliates' => [
            'enabled' => env('ORDERS_INTEGRATIONS_AFFILIATES_ENABLED', true),
        ],
        'docs' => [
            'enabled' => env('ORDERS_INTEGRATIONS_DOCS_ENABLED', false),
            'generate_pdf' => env('ORDERS_INTEGRATIONS_DOCS_GENERATE_PDF', false),
        ],
    ],

    /* Logging */
    'audit' => [
        'enabled' => env('ORDERS_AUDIT_ENABLED', true),
        'threshold' => env('ORDERS_AUDIT_THRESHOLD', 500),
    ],

    /* Notifications */
    'notifications' => [
        'payment_confirmation' => [
            'enabled' => (bool) env('ORDERS_PAYMENT_CONFIRMATION_ENABLED', true),
            'from_address' => env('ORDERS_PAYMENT_CONFIRMATION_FROM', env('MAIL_FROM_ADDRESS', '')),
            'from_name' => env('ORDERS_PAYMENT_CONFIRMATION_FROM_NAME'),
            'event_name' => env('ORDERS_PAYMENT_CONFIRMATION_EVENT_NAME', 'Order Confirmation'),
        ],
    ],
];
