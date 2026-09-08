<?php

declare(strict_types=1);

namespace AIArmada\Orders\Policies;

use AIArmada\Orders\Models\OrderRefund;
use AIArmada\Orders\Policies\Concerns\HandlesOrderRelationAuthorization;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User;

final class OrderRefundPolicy
{
    use HandlesAuthorization;
    use HandlesOrderRelationAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_order');
    }

    public function view(User $user, OrderRefund $refund): bool
    {
        return $this->canAccessOrder($user, $refund->order, 'view_order');
    }

    public function create(User $user): bool
    {
        return $this->canCreateForOwner($user, 'refund_order');
    }

    public function update(User $user, OrderRefund $refund): bool
    {
        return $this->canAccessOrder($user, $refund->order, 'refund_order');
    }

    public function delete(User $user, OrderRefund $refund): bool
    {
        return $this->canAccessOrder($user, $refund->order, 'refund_order');
    }
}
