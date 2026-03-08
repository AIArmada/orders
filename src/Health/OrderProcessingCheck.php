<?php

declare(strict_types=1);

namespace AIArmada\Orders\Health;

use AIArmada\CommerceSupport\Health\CommerceHealthCheck;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\States\PendingPayment;
use AIArmada\Orders\States\Processing;
use Illuminate\Support\Carbon;
use Spatie\Health\Checks\Result;

/**
 * Health check for order processing metrics.
 */
final class OrderProcessingCheck extends CommerceHealthCheck
{
    public ?string $name = 'Order Processing';

    /**
     * Maximum age in hours for pending orders before warning.
     */
    protected int $maxPendingHours = 24;

    /**
     * Maximum age in hours for processing orders before warning.
     */
    protected int $maxProcessingHours = 48;

    /**
     * Set the maximum hours for pending orders.
     */
    public function maxPendingHours(int $hours): self
    {
        $this->maxPendingHours = $hours;

        return $this;
    }

    /**
     * Set the maximum hours for processing orders.
     */
    public function maxProcessingHours(int $hours): self
    {
        $this->maxProcessingHours = $hours;

        return $this;
    }

    /**
     * Convenience method for setting both.
     */
    public function maxAge(int $pendingHours = 24, int $processingHours = 48): self
    {
        $this->maxPendingHours = $pendingHours;
        $this->maxProcessingHours = $processingHours;

        return $this;
    }

    /**
     * Perform the health check.
     */
    protected function performCheck(): Result
    {
        $baseQuery = Order::query();

        if ((bool) config('orders.owner.enabled', true)) {
            $owner = OwnerContext::resolve();

            if ($owner === null) {
                return $this->warning('Owner context missing; skipping order processing metrics.', [
                    'reason' => 'owner_context_missing',
                ]);
            }

            $baseQuery->forOwner($owner, includeGlobal: false);
        }

        $stuckPending = (clone $baseQuery)
            ->whereState('status', PendingPayment::class)
            ->where('created_at', '<', Carbon::now()->subHours($this->maxPendingHours))
            ->count();

        $stuckProcessing = (clone $baseQuery)
            ->whereState('status', Processing::class)
            ->where('created_at', '<', Carbon::now()->subHours($this->maxProcessingHours))
            ->count();

        $issues = [];

        if ($stuckPending > 0) {
            $issues[] = "{$stuckPending} orders pending payment for >{$this->maxPendingHours}h";
        }

        if ($stuckProcessing > 0) {
            $issues[] = "{$stuckProcessing} orders processing for >{$this->maxProcessingHours}h";
        }

        if (! empty($issues)) {
            return $this->warning(implode(', ', $issues), [
                'stuck_pending' => $stuckPending,
                'stuck_processing' => $stuckProcessing,
                'max_pending_hours' => $this->maxPendingHours,
                'max_processing_hours' => $this->maxProcessingHours,
            ]);
        }

        $todayOrders = (clone $baseQuery)
            ->whereDate('created_at', Carbon::today())
            ->count();

        return $this->success('Order processing is healthy', [
            'orders_today' => $todayOrders,
            'stuck_pending' => 0,
            'stuck_processing' => 0,
        ]);
    }
}
