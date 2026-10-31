<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Ruta;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Permisos Shield ("Accion:Ruta") + de quién es cada ruta:
 *  - administración arma las rutas de todas las bodegas;
 *  - el encargado de bodega arma las de SU bodega para sus vendedores.
 */
class RutaPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Ruta');
    }

    public function view(User $user, Ruta $ruta): bool
    {
        return $user->can('View:Ruta') && $this->esDeSuBodega($user, $ruta);
    }

    /**
     * El encargado necesita tener bodega: la ruta queda en la suya.
     */
    public function create(User $user): bool
    {
        return $user->can('Create:Ruta') && ($user->veTodasLasBodegas() || $user->bodega_id !== null);
    }

    public function update(User $user, Ruta $ruta): bool
    {
        return $user->can('Update:Ruta') && $this->esDeSuBodega($user, $ruta);
    }

    public function delete(User $user, Ruta $ruta): bool
    {
        return $user->can('Delete:Ruta') && $this->esDeSuBodega($user, $ruta);
    }

    public function restore(User $user, Ruta $ruta): bool
    {
        return $user->can('Restore:Ruta') && $this->esDeSuBodega($user, $ruta);
    }

    public function forceDelete(User $user, Ruta $ruta): bool
    {
        return $user->can('ForceDelete:Ruta') && $this->esDeSuBodega($user, $ruta);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('ForceDeleteAny:Ruta');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('RestoreAny:Ruta');
    }

    public function replicate(User $user, Ruta $ruta): bool
    {
        return $user->can('Replicate:Ruta') && $this->esDeSuBodega($user, $ruta);
    }

    public function reorder(User $user): bool
    {
        return $user->can('Reorder:Ruta');
    }

    /**
     * Administración, o el encargado de la bodega de la ruta.
     */
    private function esDeSuBodega(User $user, Ruta $ruta): bool
    {
        return $user->veTodasLasBodegas()
            || ($user->bodega_id !== null && $ruta->bodega_id === $user->bodega_id);
    }
}
