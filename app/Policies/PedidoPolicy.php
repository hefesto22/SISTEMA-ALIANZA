<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Pedido;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Permisos Shield ("Accion:Pedido") + a quién le toca cada pedido:
 *  - el vendedor solo toca los suyos y solo mientras están pendientes;
 *  - bodega valida los pendientes de SU bodega;
 *  - administración puede con todos.
 */
class PedidoPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Pedido');
    }

    public function view(User $user, Pedido $pedido): bool
    {
        return $user->can('View:Pedido') && $pedido->esVisiblePara($user);
    }

    public function create(User $user): bool
    {
        return $user->can('Create:Pedido');
    }

    public function update(User $user, Pedido $pedido): bool
    {
        return $user->can('Update:Pedido')
            && $pedido->estaPendiente()
            && $pedido->esVisiblePara($user);
    }

    /**
     * En la práctica "eliminar" es anular: el pedido queda con historial.
     */
    public function delete(User $user, Pedido $pedido): bool
    {
        return $user->can('Delete:Pedido')
            && $pedido->estaPendiente()
            && $pedido->esVisiblePara($user);
    }

    public function validar(User $user, Pedido $pedido): bool
    {
        if (! $user->can('Validar:Pedido') || ! $pedido->estaPendiente()) {
            return false;
        }

        return $user->veTodasLasBodegas()
            || ($user->bodega_id !== null && $user->bodega_id === $pedido->bodega_id);
    }
}
