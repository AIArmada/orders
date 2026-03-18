<?php

declare(strict_types=1);

namespace AIArmada\Orders;

use AIArmada\Docs\Contracts\DocServiceInterface;
use AIArmada\Orders\Contracts\OrderServiceInterface;
use AIArmada\Orders\Services\OrderService;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class OrdersServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('orders')
            ->hasConfigFile()
            ->hasTranslations()
            ->hasViews()
            ->discoversMigrations();
    }

    public function registeringPackage(): void
    {
        $this->app->bind(OrderServiceInterface::class, OrderService::class);
        $this->registerEventListeners();
    }

    public function bootingPackage(): void
    {
        $this->registerPolicies();
    }

    protected function registerPolicies(): void
    {
        Gate::policy(Models\Order::class, Policies\OrderPolicy::class);
        Gate::policy(Models\OrderItem::class, Policies\OrderItemPolicy::class);
    }

    protected function registerEventListeners(): void
    {
        if (! config('orders.integrations.docs.enabled', true)) {
            return;
        }

        if (! interface_exists(DocServiceInterface::class)) {
            return;
        }

        $dispatcher = $this->app->make(Dispatcher::class);
        $dispatcher->listen(Events\OrderPaid::class, Listeners\CreateInvoiceForPaidOrder::class);
    }
}
