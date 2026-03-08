<?php

declare(strict_types=1);

namespace AIArmada\Orders\Policies;

use AIArmada\Orders\Models\Order;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User;

final class OrderPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_order');
    }

    public function view(User $user, Order $order): bool
    {
        return $user->can('view_order');
    }

    public function create(User $user): bool
    {
        return $user->can('create_order');
    }

    public function update(User $user, Order $order): bool
    {
        if ($order->isFinal()) {
            return false;
        }

        return $user->can('update_order');
    }

    public function addNote(User $user, Order $order): bool
    {
        if (! $this->view($user, $order)) {
            return false;
        }

        return $user->can('update_order') || $user->can('add_order_note');
    }

    public function delete(User $user, Order $order): bool
    {
        return $user->can('delete_order');
    }

    public function cancel(User $user, Order $order): bool
    {
        if (! $order->canBeCanceled()) {
            return false;
        }

        return $user->can('cancel_order');
    }

    public function refund(User $user, Order $order): bool
    {
        if (! $order->canBeRefunded()) {
            return false;
        }

        return $user->can('refund_order');
    }
}
