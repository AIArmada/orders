<?php

declare(strict_types=1);

namespace AIArmada\Orders\Policies;

use AIArmada\Orders\Models\OrderNote;
use AIArmada\Orders\Policies\Concerns\HandlesOrderRelationAuthorization;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User;

final class OrderNotePolicy
{
    use HandlesAuthorization;
    use HandlesOrderRelationAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_order');
    }

    public function view(User $user, OrderNote $note): bool
    {
        return $this->canAccessOrder($user, $note->order, 'view_order');
    }

    public function create(User $user): bool
    {
        return $this->canCreateForOwner($user, 'add_order_note');
    }

    public function update(User $user, OrderNote $note): bool
    {
        return $this->canAccessOrder($user, $note->order, 'update_order');
    }

    public function delete(User $user, OrderNote $note): bool
    {
        return $this->canAccessOrder($user, $note->order, 'update_order');
    }
}
