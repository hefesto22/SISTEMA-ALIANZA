<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Permisos en formato Shield ("Accion:Modelo"); se asignan por rol
 * desde el panel (Roles) o con RolesSistemaSeeder.
 */
class CambioPrecioPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CambioPrecio');
    }

    public function view(AuthUser $authUser): bool
    {
        return $authUser->can('View:CambioPrecio');
    }
}
