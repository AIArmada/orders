<?php

declare(strict_types=1);

namespace AIArmada\Orders\Policies;

use AIArmada\Orders\Models\OrderAddress;
use AIArmada\Orders\Policies\Concerns\HandlesOrderRelationAuthorization;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User;

final class OrderAddressPolicy
{
    use HandlesAuthorization;
    use HandlesOrderRelationAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_order');
    }

    public function view(User $user, OrderAddress $address): bool
    {
        return $this->canAccessOrder($user, $address->order, 'view_order');
    }

    public function create(User $user): bool
    {
        return $this->canCreateForOwner($user, 'create_order');
    }

    public function update(User $user, OrderAddress $address): bool
    {
        return $this->canAccessOrder($user, $address->order, 'update_order');
    }

    public function delete(User $user, OrderAddress $address): bool
    {
        return $this->canAccessOrder($user, $address->order, 'update_order');
    }
}
