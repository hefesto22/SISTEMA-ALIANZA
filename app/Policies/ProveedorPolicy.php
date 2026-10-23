<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Permisos en formato Shield ("Accion:Modelo"); se asignan por rol
 * desde el panel (Roles) o con RolesSistemaSeeder.
 */
class ProveedorPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Proveedor');
    }

    public function view(AuthUser $authUser): bool
    {
        return $authUser->can('View:Proveedor');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Proveedor');
    }

    public function update(AuthUser $authUser): bool
    {
        return $authUser->can('Update:Proveedor');
    }

    public function delete(AuthUser $authUser): bool
    {
        return $authUser->can('Delete:Proveedor');
    }

    public function restore(AuthUser $authUser): bool
    {
        return $authUser->can('Restore:Proveedor');
    }

    public function forceDelete(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDelete:Proveedor');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Proveedor');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Proveedor');
    }

    public function replicate(AuthUser $authUser): bool
    {
        return $authUser->can('Replicate:Proveedor');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Proveedor');
    }
}
