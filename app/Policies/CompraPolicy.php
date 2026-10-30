<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Compra;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Permisos Shield ("Accion:Compra") + de qué bodega es la compra:
 * administración ve todas; el encargado, solo las de su bodega.
 *
 * Una compra registrada no se edita ni se borra: ya movió existencia y
 * costos. Lo que entró mal se corrige con un ajuste por conteo.
 */
class CompraPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Compra');
    }

    public function view(User $user, Compra $compra): bool
    {
        return $user->can('View:Compra')
            && ($user->veTodasLasBodegas() || ($user->bodega_id !== null && $compra->bodega_id === $user->bodega_id));
    }

    public function create(User $user): bool
    {
        return $user->can('Create:Compra');
    }

    public function update(User $user, Compra $compra): bool
    {
        return false;
    }

    public function delete(User $user, Compra $compra): bool
    {
        return false;
    }
}
