<?php

declare(strict_types=1);

namespace AIArmada\Orders\Policies\Concerns;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Orders\Models\Order;
use Illuminate\Foundation\Auth\User;

trait HandlesOrderRelationAuthorization
{
    private function canAccessOrder(User $user, Order $order, string $permission): bool
    {
        if (! $user->can($permission)) {
            return false;
        }

        if (! (bool) config('orders.owner.enabled', false)) {
            return true;
        }

        $owner = OwnerContext::resolve();

        if ($owner === null) {
            return OwnerContext::isExplicitGlobal() && ! $order->hasOwner();
        }

        if ($order->belongsToOwner($owner)) {
            return true;
        }

        return (bool) config('orders.owner.include_global', false) && ! $order->hasOwner();
    }

    private function canCreateForOwner(User $user, string $permission): bool
    {
        if (! $user->can($permission)) {
            return false;
        }

        if (! (bool) config('orders.owner.enabled', false)) {
            return true;
        }

        return OwnerContext::resolve() !== null || OwnerContext::isExplicitGlobal();
    }
}
