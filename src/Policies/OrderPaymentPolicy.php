<?php

declare(strict_types=1);

namespace AIArmada\Orders\Policies;

use AIArmada\Orders\Models\OrderPayment;
use AIArmada\Orders\Policies\Concerns\HandlesOrderRelationAuthorization;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User;

final class OrderPaymentPolicy
{
    use HandlesAuthorization;
    use HandlesOrderRelationAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_order');
    }

    public function view(User $user, OrderPayment $payment): bool
    {
        return $this->canAccessOrder($user, $payment->order, 'view_order');
    }

    public function create(User $user): bool
    {
        return $this->canCreateForOwner($user, 'create_order');
    }

    public function update(User $user, OrderPayment $payment): bool
    {
        return $this->canAccessOrder($user, $payment->order, 'update_order');
    }

    public function delete(User $user, OrderPayment $payment): bool
    {
        return $this->canAccessOrder($user, $payment->order, 'update_order');
    }
}
