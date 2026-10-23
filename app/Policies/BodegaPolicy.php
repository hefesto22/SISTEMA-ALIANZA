<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Permisos en formato Shield ("Accion:Modelo"); se asignan por rol
 * desde el panel (Roles) o con RolesSistemaSeeder.
 */
class BodegaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Bodega');
    }

    public function view(AuthUser $authUser): bool
    {
        return $authUser->can('View:Bodega');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Bodega');
    }

    public function update(AuthUser $authUser): bool
    {
        return $authUser->can('Update:Bodega');
    }

    public function delete(AuthUser $authUser): bool
    {
        return $authUser->can('Delete:Bodega');
    }

    public function restore(AuthUser $authUser): bool
    {
        return $authUser->can('Restore:Bodega');
    }

    public function forceDelete(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDelete:Bodega');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Bodega');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Bodega');
    }

    public function replicate(AuthUser $authUser): bool
    {
        return $authUser->can('Replicate:Bodega');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Bodega');
    }
}
