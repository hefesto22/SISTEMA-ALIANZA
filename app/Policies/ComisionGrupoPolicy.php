<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ComisionGrupo;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Permisos Shield ("Accion:ComisionGrupo"). Administración arma los grupos
 * y sus escalas; el encargado de bodega los ve. Uno que ya se pagó no se
 * borra: se desactiva.
 */
class ComisionGrupoPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:ComisionGrupo');
    }

    public function view(User $user, ComisionGrupo $grupo): bool
    {
        return $user->can('View:ComisionGrupo');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:ComisionGrupo');
    }

    public function update(User $user, ComisionGrupo $grupo): bool
    {
        return $user->can('Update:ComisionGrupo');
    }

    public function delete(User $user, ComisionGrupo $grupo): bool
    {
        return $user->can('Delete:ComisionGrupo') && ! $grupo->seUso();
    }
}
