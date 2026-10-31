<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Promocion;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Permisos Shield ("Accion:Promocion") + de quién es cada promoción:
 *  - administración arma y cambia las de todas las bodegas;
 *  - el encargado crea las de su bodega y solo cambia las que son
 *    únicamente de su bodega (las de varias o de todas, solo las ve);
 *  - el vendedor ve las que valen en su bodega.
 */
class PromocionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Promocion');
    }

    public function view(User $user, Promocion $promocion): bool
    {
        return $user->can('View:Promocion') && $promocion->esVisiblePara($user);
    }

    /**
     * El encargado necesita tener bodega: la promoción queda en la suya.
     */
    public function create(User $user): bool
    {
        return $user->can('Create:Promocion') && ($user->veTodasLasBodegas() || $user->bodega_id !== null);
    }

    public function update(User $user, Promocion $promocion): bool
    {
        return $user->can('Update:Promocion') && $promocion->esEditablePor($user);
    }

    public function delete(User $user, Promocion $promocion): bool
    {
        return $user->can('Delete:Promocion') && $promocion->esEditablePor($user);
    }

    public function restore(User $user, Promocion $promocion): bool
    {
        return $user->can('Restore:Promocion') && $promocion->esEditablePor($user);
    }

    public function forceDelete(User $user, Promocion $promocion): bool
    {
        return $user->can('ForceDelete:Promocion') && $promocion->esEditablePor($user);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('ForceDeleteAny:Promocion');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('RestoreAny:Promocion');
    }

    public function replicate(User $user, Promocion $promocion): bool
    {
        return $user->can('Replicate:Promocion') && $promocion->esEditablePor($user);
    }

    public function reorder(User $user): bool
    {
        return $user->can('Reorder:Promocion');
    }
}
