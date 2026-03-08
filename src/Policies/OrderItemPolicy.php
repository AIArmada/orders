<?php

declare(strict_types=1);

namespace AIArmada\Orders\Policies;

use AIArmada\Orders\Models\OrderItem;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User;

final class OrderItemPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_order');
    }

    public function view(User $user, OrderItem $item): bool
    {
        return $user->can('view_order');
    }

    public function create(User $user): bool
    {
        return $user->can('create_order');
    }

    public function update(User $user, OrderItem $item): bool
    {
        if ($item->order->isFinal()) {
            return false;
        }

        return $user->can('update_order');
    }

    public function delete(User $user, OrderItem $item): bool
    {
        if ($item->order->isFinal()) {
            return false;
        }

        return $user->can('update_order');
    }
}
