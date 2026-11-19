<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Devolucion;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Devoluciones: administración y bodega las registran (desde el pedido) y
 * las consultan; no se editan ni se borran a mano.
 */
class DevolucionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Devolucion');
    }

    public function view(User $user, Devolucion $devolucion): bool
    {
        return $user->can('View:Devolucion') && $devolucion->pedido->esVisiblePara($user);
    }

    public function create(User $user): bool
    {
        return $user->can('Create:Devolucion');
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
