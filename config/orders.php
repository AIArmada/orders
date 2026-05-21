<?php

declare(strict_types=1);

return [
    /* Database */
    'database' => [
        'tables' => [
            'orders' => 'orders',
            'order_items' => 'order_items',
            'order_addresses' => 'order_addresses',
            'order_payments' => 'order_payments',
            'order_refunds' => 'order_refunds',
            'order_notes' => 'order_notes',
        ],
        'json_column_type' => env('ORDERS_JSON_COLUMN_TYPE', env('COMMERCE_JSON_COLUMN_TYPE', 'json')),
    ],

    /* Defaults */
    'currency' => [
        'default' => 'MYR',
        'decimal_places' => 2,
    ],

    /* Features */
    'owner' => [
        'enabled' => env('ORDERS_OWNER_ENABLED', true),
        'include_global' => env('ORDERS_OWNER_INCLUDE_GLOBAL', false),
        'auto_assign_on_create' => env('ORDERS_OWNER_AUTO_ASSIGN_ON_CREATE', true),
    ],

    /* Behavior */
    'status' => [
        'allowed' => [ // created, pending_payment, processing
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

    /* Integrations */
    'integrations' => [
        'inventory' => [
            'enabled' => env('ORDERS_INTEGRATIONS_INVENTORY_ENABLED', true),
        ],
        'affiliates' => [
            'enabled' => env('ORDERS_INTEGRATIONS_AFFILIATES_ENABLED', true),
        ],
        'docs' => [
            'enabled' => env('ORDERS_INTEGRATIONS_DOCS_ENABLED', true),
            'generate_pdf' => env('ORDERS_INTEGRATIONS_DOCS_GENERATE_PDF', false),
        ],
    ],

    /* Logging */
    'audit' => [
        'enabled' => env('ORDERS_AUDIT_ENABLED', true),
        'threshold' => env('ORDERS_AUDIT_THRESHOLD', 500),
    ],
];
