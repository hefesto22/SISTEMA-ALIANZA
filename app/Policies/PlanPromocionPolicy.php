<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlanPromocion;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Los planes del proveedor usan los permisos de promociones (Shield
 * "Accion:Promocion"), sin el vendedor:
 *  - administración arma y cambia los de todas las bodegas;
 *  - el encargado arma los de su bodega y solo cambia los que son
 *    únicamente de su bodega (los de varias o de todas, solo los ve);
 *  - el vendedor no los ve: en la preventa ve las promociones del plan.
 * Un plan que ya se dio en pedidos no se borra.
 */
class PlanPromocionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Promocion') && ! $user->esVendedor();
    }

    public function view(User $user, PlanPromocion $plan): bool
    {
        return $user->can('View:Promocion') && ! $user->esVendedor() && $plan->esVisiblePara($user);
    }

    public function create(User $user): bool
    {
        return $user->can('Create:Promocion') && ! $user->esVendedor() && ($user->veTodasLasBodegas() || $user->bodega_id !== null);
    }

    public function update(User $user, PlanPromocion $plan): bool
    {
        return $user->can('Update:Promocion') && ! $user->esVendedor() && $plan->esEditablePor($user);
    }

    public function delete(User $user, PlanPromocion $plan): bool
    {
        return $user->can('Delete:Promocion') && ! $user->esVendedor() && $plan->esEditablePor($user) && ! $plan->seUso();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
