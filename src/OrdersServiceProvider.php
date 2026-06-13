<?php

declare(strict_types=1);

namespace AIArmada\Orders;

use AIArmada\Docs\Contracts\DocServiceInterface;
use AIArmada\Orders\Contracts\OrderServiceInterface;
use AIArmada\Orders\Notifications\PaymentConfirmationNotification;
use AIArmada\Orders\Services\OrderService;
use AIArmada\Orders\Support\OrderHandlerRegistrar;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
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
            ->runsMigrations()
            ->discoversMigrations();
    }

    public function registeringPackage(): void
    {
        $this->app->singleton(OrderHandlerRegistrar::class);
        $this->app->bind(OrderServiceInterface::class, OrderService::class);
        $this->registerEventListeners();
        $this->registerTransitionListeners();
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
        $dispatcher = $this->app->make(Dispatcher::class);

        if (config('orders.integrations.docs.enabled', false)) {
            if (interface_exists(DocServiceInterface::class)) {
                $dispatcher->listen(Events\OrderPaid::class, Listeners\CreateInvoiceForPaidOrder::class);
            }
        }

        if ((bool) config('orders.notifications.payment_confirmation.enabled', true)) {
            $dispatcher->listen(Events\OrderPaid::class, function (Events\OrderPaid $event): void {
                $notification = new PaymentConfirmationNotification(
                    $event->order,
                    $event->transactionId,
                    $event->gateway,
                );

                $recipient = $event->order->routeNotificationForMail($notification);

                if ($recipient === null) {
                    return;
                }

                Notification::route('mail', $recipient)->notify($notification);
            });
        }
    }

    protected function registerTransitionListeners(): void
    {
        $dispatcher = $this->app->make(Dispatcher::class);

        if (config('orders.integrations.inventory.enabled', true)) {
            $dispatcher->listen(Events\OrderProcessingStarted::class, Listeners\DeductInventoryOnPaymentConfirmed::class);
            $dispatcher->listen(Events\OrderCancelInitiated::class, Listeners\ReleaseInventoryOnOrderCanceled::class);
        }
    }
}
