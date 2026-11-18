<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ComisionMeta;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Permisos Shield ("Accion:ComisionMeta") + de quién es la meta:
 * administración, de cualquier vendedor; el encargado, de los de su bodega.
 */
class ComisionMetaPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:ComisionMeta');
    }

    public function view(User $user, ComisionMeta $meta): bool
    {
        return $user->can('View:ComisionMeta') && self::esDeSuBodega($user, $meta);
    }

    public function create(User $user): bool
    {
        return $user->can('Create:ComisionMeta');
    }

    public function update(User $user, ComisionMeta $meta): bool
    {
        return $user->can('Update:ComisionMeta') && self::esDeSuBodega($user, $meta);
    }

    public function delete(User $user, ComisionMeta $meta): bool
    {
        return $user->can('Delete:ComisionMeta') && self::esDeSuBodega($user, $meta);
    }

    private static function esDeSuBodega(User $user, ComisionMeta $meta): bool
    {
        return $user->veTodasLasBodegas()
            || ($user->bodega_id !== null && $meta->vendedor->bodega_id === $user->bodega_id);
    }
}
