<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Carga;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Las cargas las arman y las liquidan los que despachan: los que validan
 * pedidos (Shield "Validar:Pedido", bodega y administración). Administración
 * ve las de todas las bodegas; el encargado, las de la suya. Mientras va en
 * ruta se cambia lo que lleva o se borra la carga; liquidada, solo se ve.
 */
class CargaPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('Validar:Pedido');
    }

    public function view(User $user, Carga $carga): bool
    {
        return $this->leToca($user, $carga);
    }

    public function create(User $user): bool
    {
        return $user->can('Validar:Pedido') && ($user->veTodasLasBodegas() || $user->bodega_id !== null);
    }

    public function update(User $user, Carga $carga): bool
    {
        return $carga->enRuta() && $this->leToca($user, $carga);
    }

    public function liquidar(User $user, Carga $carga): bool
    {
        return $carga->enRuta() && $this->leToca($user, $carga);
    }

    public function delete(User $user, Carga $carga): bool
    {
        return $carga->enRuta() && $this->leToca($user, $carga);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function leToca(User $user, Carga $carga): bool
    {
        if (! $user->can('Validar:Pedido')) {
            return false;
        }

        return $user->veTodasLasBodegas() || ($user->bodega_id !== null && $carga->bodega_id === $user->bodega_id);
    }
}
