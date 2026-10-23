<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Factura;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Facturas: solo se consultan. Se emiten al imprimir el pedido y se anulan
 * al corregirlo; nadie las crea, edita ni borra a mano.
 */
class FacturaPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Factura');
    }

    public function view(User $user, Factura $factura): bool
    {
        return $user->can('View:Factura') && $factura->pedido->esVisiblePara($user);
    }

    public function create(): bool
    {
        return false;
    }

    public function update(): bool
    {
        return false;
    }

    public function delete(): bool
    {
        return false;
    }
}
