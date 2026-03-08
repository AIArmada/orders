<?php

declare(strict_types=1);

namespace AIArmada\Orders\States;

use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

/**
 * Abstract base class for all order states.
 *
 * This class defines the complete order lifecycle state machine using
 * spatie/laravel-model-states. Each concrete state class defines its
 * appearance (color, icon, label) and capabilities (canCancel, canRefund, etc.).
 *
 * State Diagram:
 *
 *                              ┌─────────────┐
 *                              │   CREATED   │
 *                              └──────┬──────┘
 *                                     │
 *                                     ▼
 *                           ┌─────────────────┐
 *         ┌─────────────────│PENDING_PAYMENT  │─────────────────┐
 *         │                 └────────┬────────┘                 │
 *         │                          │                          │
 *         ▼                          ▼                          ▼
 *  ┌────────────┐           ┌────────────────┐          ┌────────────┐
 *  │  CANCELED  │           │   PROCESSING   │          │   FAILED   │
 *  └────────────┘           └───────┬────────┘          └────────────┘
 *         ▲                         │
 *         │          ┌──────────────┼──────────────┐
 *         │          ▼              ▼              ▼
 *         │   ┌────────────┐  ┌────────────┐  ┌────────────┐
 *         └───│  ON_HOLD   │  │  SHIPPED   │  │   FRAUD    │
 *             └─────┬──────┘  └──────┬─────┘  └────────────┘
 *                   │                │
 *                   │                ├──────────────┐
 *                   ▼                ▼              ▼
 *             ┌────────────┐  ┌────────────┐  ┌────────────┐
 *             │ PROCESSING │  │ DELIVERED  │  │  RETURNED  │
 *             └────────────┘  └──────┬─────┘  └──────┬─────┘
 *                                    │              │
 *                                    ▼              ▼
 *                             ┌────────────┐  ┌────────────┐
 *                             │ COMPLETED  │  │  REFUNDED  │
 *                             └────────────┘  └────────────┘
 */
abstract class OrderStatus extends State
{
    /**
     * Get the display color for Filament badges.
     */
    abstract public function color(): string;

    /**
     * Get the heroicon name for display.
     */
    abstract public function icon(): string;

    /**
     * Get the translatable label.
     */
    abstract public function label(): string;

    /**
     * Configure all allowed state transitions.
     */
    final public static function config(): StateConfig
    {
        return parent::config()
            ->default(self::resolveDefaultStateClass())
            // Initial → Payment
            ->allowTransition(Created::class, PendingPayment::class)
            ->allowTransition(Created::class, Processing::class)
            // Payment outcomes
            ->allowTransition(PendingPayment::class, Processing::class)
            ->allowTransition(PendingPayment::class, Canceled::class)
            ->allowTransition(PendingPayment::class, PaymentFailed::class)
            // Processing flow
            ->allowTransition(Processing::class, OnHold::class)
            ->allowTransition(Processing::class, Fraud::class)
            ->allowTransition(Processing::class, Shipped::class)
            ->allowTransition(Processing::class, Canceled::class)
            // Hold management
            ->allowTransition(OnHold::class, Processing::class)
            ->allowTransition(OnHold::class, Canceled::class)
            // Shipping → Delivery
            ->allowTransition(Shipped::class, Delivered::class)
            ->allowTransition(Shipped::class, Returned::class)
            // Completion
            ->allowTransition(Delivered::class, Completed::class)
            ->allowTransition(Delivered::class, Returned::class)
            // Returns
            ->allowTransition(Returned::class, Refunded::class);
    }

    /**
     * Whether the order can be canceled in this state.
     * Override in child classes to enable cancellation.
     */
    public function canCancel(): bool
    {
        return false;
    }

    /**
     * Whether a refund can be processed in this state.
     * Override in child classes to enable refunds.
     */
    public function canRefund(): bool
    {
        return false;
    }

    /**
     * Whether the order can be modified in this state.
     * Override in child classes to enable modification.
     */
    public function canModify(): bool
    {
        return false;
    }

    /**
     * Whether this is a final/terminal state.
     * Override in child classes for terminal states.
     */
    public function isFinal(): bool
    {
        return false;
    }

    /**
     * Get the state name (e.g., 'delivered', 'processing').
     *
     * This method provides a convenient way to access the state name
     * without relying on the static property directly.
     */
    public function name(): string
    {
        return $this->getValue();
    }

    /**
     * @return class-string<OrderStatus>
     */
    private static function resolveDefaultStateClass(): string
    {
        $default = config('orders.status.default', Processing::class);

        /** @var array<int, string> $allowed */
        $allowed = array_values(array_filter((array) config('orders.status.allowed', []), 'is_string'));

        if (is_string($default) && class_exists($default) && is_subclass_of($default, self::class)) {
            return $default;
        }

        $defaultValue = is_string($default) ? $default : '';

        if ($defaultValue !== '' && $allowed !== [] && ! in_array($defaultValue, $allowed, true)) {
            return Processing::class;
        }

        return match ($defaultValue) {
            'created' => Created::class,
            'pending_payment' => PendingPayment::class,
            'processing' => Processing::class,
            default => Processing::class,
        };
    }
}
