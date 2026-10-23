<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Permisos en formato Shield ("Accion:Modelo"); se asignan por rol
 * desde el panel (Roles) o con RolesSistemaSeeder.
 */
class ClientePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Cliente');
    }

    public function view(AuthUser $authUser): bool
    {
        return $authUser->can('View:Cliente');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Cliente');
    }

    public function update(AuthUser $authUser): bool
    {
        return $authUser->can('Update:Cliente');
    }

    public function delete(AuthUser $authUser): bool
    {
        return $authUser->can('Delete:Cliente');
    }

    public function restore(AuthUser $authUser): bool
    {
        return $authUser->can('Restore:Cliente');
    }

    public function forceDelete(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDelete:Cliente');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Cliente');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Cliente');
    }

    public function replicate(AuthUser $authUser): bool
    {
        return $authUser->can('Replicate:Cliente');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Cliente');
    }
}
