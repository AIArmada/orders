---
title: Installation
---

# Installation

## Requirements

- PHP 8.4 or higher
- Laravel 12.x
- Composer

## Install via Composer

```bash
composer require aiarmada/orders
```

## Publish Configuration

```bash
php artisan vendor:publish --tag=orders-config
```

## Run Migrations

```bash
php artisan migrate
```

## Publish Views (Optional)

To customize invoice templates:

```bash
php artisan vendor:publish --tag=orders-views
```

## Publish Translations (Optional)

```bash
php artisan vendor:publish --tag=orders-translations
```

## Service Provider

The package auto-registers its service provider. If you need to manually register it:

```php
// config/app.php
'providers' => [
    // ...
    AIArmada\Orders\OrdersServiceProvider::class,
],
```

## Dependencies

The package will automatically install required dependencies:

- `spatie/laravel-model-states` - State machine for order status
- `spatie/laravel-pdf` - PDF generation for invoices

## Multi-tenancy Setup

If using multi-tenancy, ensure the `OwnerResolverInterface` is bound:

```php
// AppServiceProvider.php
use AIArmada\CommerceSupport\Contracts\OwnerResolverInterface;

public function register(): void
{
    $this->app->bind(OwnerResolverInterface::class, function () {
        return new class implements OwnerResolverInterface {
            public function resolve(): ?Model
            {
                return auth()->user()?->currentTenant;
            }
        };
    });
}
```

## Database Tables

The following tables are created:

| Table | Description |
|-------|-------------|
| `orders` | Main order records |
| `order_items` | Line items for orders |
| `order_addresses` | Billing/shipping addresses |
| `order_payments` | Payment records |
| `order_refunds` | Refund records |
| `order_notes` | Order notes |

## Next Steps

- [Configuration](03-configuration.md)
- [Usage Guide](04-usage.md)
- [State Machine](05-state-machine.md)
