<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Permisos en formato Shield ("Accion:Modelo"); se asignan por rol
 * desde el panel (Roles) o con RolesSistemaSeeder.
 */
class CategoriaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Categoria');
    }

    public function view(AuthUser $authUser): bool
    {
        return $authUser->can('View:Categoria');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Categoria');
    }

    public function update(AuthUser $authUser): bool
    {
        return $authUser->can('Update:Categoria');
    }

    public function delete(AuthUser $authUser): bool
    {
        return $authUser->can('Delete:Categoria');
    }
}
