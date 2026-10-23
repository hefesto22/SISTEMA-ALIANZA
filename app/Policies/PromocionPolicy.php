<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Permisos en formato Shield ("Accion:Modelo"); se asignan por rol
 * desde el panel (Roles) o con RolesSistemaSeeder.
 */
class PromocionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Promocion');
    }

    public function view(AuthUser $authUser): bool
    {
        return $authUser->can('View:Promocion');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Promocion');
    }

    public function update(AuthUser $authUser): bool
    {
        return $authUser->can('Update:Promocion');
    }

    public function delete(AuthUser $authUser): bool
    {
        return $authUser->can('Delete:Promocion');
    }

    public function restore(AuthUser $authUser): bool
    {
        return $authUser->can('Restore:Promocion');
    }

    public function forceDelete(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDelete:Promocion');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Promocion');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Promocion');
    }

    public function replicate(AuthUser $authUser): bool
    {
        return $authUser->can('Replicate:Promocion');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Promocion');
    }
}
