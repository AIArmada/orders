<?php

declare(strict_types=1);

namespace AIArmada\Orders\Support;

use AIArmada\Orders\Contracts\FulfillmentHandler;
use AIArmada\Orders\Contracts\InventoryHandler;
use AIArmada\Orders\Contracts\PaymentHandler;
use Illuminate\Contracts\Container\Container;

final class OrderHandlerRegistrar
{
    private bool $fulfillmentRegistered = false;

    private bool $inventoryRegistered = false;

    private bool $paymentRegistered = false;

    public function __construct(
        private readonly Container $app,
    ) {}

    public function registerFulfillmentHandler(string $abstract): void
    {
        if ($this->fulfillmentRegistered) {
            return;
        }

        $this->app->bind(FulfillmentHandler::class, $abstract);
        $this->fulfillmentRegistered = true;
    }

    public function registerInventoryHandler(string $abstract): void
    {
        if ($this->inventoryRegistered) {
            return;
        }

        $this->app->bind(InventoryHandler::class, $abstract);
        $this->inventoryRegistered = true;
    }

    public function registerPaymentHandler(string $abstract): void
    {
        if ($this->paymentRegistered) {
            return;
        }

        $this->app->bind(PaymentHandler::class, $abstract);
        $this->paymentRegistered = true;
    }

    public function isFulfillmentRegistered(): bool
    {
        return $this->fulfillmentRegistered;
    }

    public function isInventoryRegistered(): bool
    {
        return $this->inventoryRegistered;
    }

    public function isPaymentRegistered(): bool
    {
        return $this->paymentRegistered;
    }
}
