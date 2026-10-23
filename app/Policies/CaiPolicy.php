<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Cai;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * CAI y datos fiscales (Administración). Permisos Shield "Accion:Cai".
 * Un CAI con facturas emitidas no se borra (se desactiva).
 */
class CaiPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Cai');
    }

    public function view(AuthUser $authUser): bool
    {
        return $authUser->can('View:Cai');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Cai');
    }

    public function update(AuthUser $authUser): bool
    {
        return $authUser->can('Update:Cai');
    }

    public function delete(AuthUser $authUser, Cai $cai): bool
    {
        return $authUser->can('Delete:Cai') && ! $cai->facturas()->exists();
    }
}
