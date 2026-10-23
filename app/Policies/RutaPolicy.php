<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Permisos en formato Shield ("Accion:Modelo"); se asignan por rol
 * desde el panel (Roles) o con RolesSistemaSeeder.
 */
class RutaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Ruta');
    }

    public function view(AuthUser $authUser): bool
    {
        return $authUser->can('View:Ruta');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Ruta');
    }

    public function update(AuthUser $authUser): bool
    {
        return $authUser->can('Update:Ruta');
    }

    public function delete(AuthUser $authUser): bool
    {
        return $authUser->can('Delete:Ruta');
    }

    public function restore(AuthUser $authUser): bool
    {
        return $authUser->can('Restore:Ruta');
    }

    public function forceDelete(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDelete:Ruta');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Ruta');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Ruta');
    }

    public function replicate(AuthUser $authUser): bool
    {
        return $authUser->can('Replicate:Ruta');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Ruta');
    }
}
