<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Pedido;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Permisos Shield ("Accion:Pedido") + a quién le toca cada pedido:
 *  - el vendedor solo toca los suyos y solo mientras están pendientes;
 *  - el encargado de bodega ve, valida, corrige e imprime los de los
 *    vendedores de SU bodega;
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

    /**
     * Bodega valida lo que sale de un pedido pendiente.
     */
    public function validar(User $user, Pedido $pedido): bool
    {
        return $pedido->estaPendiente() && $this->leTocaABodega($user, $pedido);
    }

    /**
     * Corregir lo que sale de un pedido ya validado (por imprimir o impreso).
     */
    public function corregir(User $user, Pedido $pedido): bool
    {
        return $pedido->estaValidado() && $this->leTocaABodega($user, $pedido);
    }

    /**
     * Imprimirlo cuenta como impresión (pasa a Impreso); los demás solo ven la hoja.
     */
    public function imprimir(User $user, Pedido $pedido): bool
    {
        return $pedido->estaValidado() && $this->leTocaABodega($user, $pedido);
    }

    /**
     * Administración, o el encargado de la bodega de ese vendedor.
     */
    private function leTocaABodega(User $user, Pedido $pedido): bool
    {
        if (! $user->can('Validar:Pedido')) {
            return false;
        }

        return $user->veTodasLasBodegas()
            || ($user->bodega_id !== null && $pedido->vendedor->bodega_id === $user->bodega_id);
    }
}
