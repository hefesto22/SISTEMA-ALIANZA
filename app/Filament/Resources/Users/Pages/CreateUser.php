<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
    {
        /** @var User $usuario */
        $usuario = $this->getRecord();
        $usuario->unsetRelation('roles');

        UserResource::registrarCambioDeRoles($usuario, [], UserResource::nombresDeRoles($usuario));
    }
}
