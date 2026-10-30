<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Usuarios. Además del permiso:
 *  - nadie se elimina a sí mismo ni elimina a un super admin;
 *  - quien tiene pedidos no se elimina (se desactiva), para que sus pedidos
 *    y facturas sigan con su nombre.
 */
class UserPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:User');
    }

    public function view(AuthUser $authUser): bool
    {
        return $authUser->can('View:User');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:User');
    }

    public function update(AuthUser $authUser): bool
    {
        return $authUser->can('Update:User');
    }

    public function delete(AuthUser $authUser, User $user): bool
    {
        return $authUser->can('Delete:User') && $this->sePuedeEliminar($authUser, $user);
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('Delete:User');
    }

    public function restore(AuthUser $authUser): bool
    {
        return $authUser->can('Restore:User');
    }

    public function forceDelete(AuthUser $authUser, User $user): bool
    {
        return $authUser->can('ForceDelete:User') && $this->sePuedeEliminar($authUser, $user);
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:User');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:User');
    }

    public function replicate(AuthUser $authUser): bool
    {
        return $authUser->can('Replicate:User');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:User');
    }

    private function sePuedeEliminar(AuthUser $authUser, User $user): bool
    {
        return $user->getKey() !== $authUser->getKey()
            && ! $user->esSuperAdmin()
            && ! $user->tienePedidos();
    }
}
