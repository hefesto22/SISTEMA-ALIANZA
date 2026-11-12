<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Permisos en formato Shield ("Accion:UnidadMedida"); se asignan por rol
 * desde el panel (Roles) o con RolesSistemaSeeder. No se borran: se
 * desactivan (pueden estar en presentaciones de productos).
 */
class UnidadMedidaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:UnidadMedida');
    }

    public function view(AuthUser $authUser): bool
    {
        return $authUser->can('View:UnidadMedida');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:UnidadMedida');
    }

    public function update(AuthUser $authUser): bool
    {
        return $authUser->can('Update:UnidadMedida');
    }

    public function delete(): bool
    {
        return false;
    }

    public function deleteAny(): bool
    {
        return false;
    }
}
