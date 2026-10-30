<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @var list<string> */
    private array $rolesAntes = [];

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function beforeSave(): void
    {
        $this->rolesAntes = UserResource::nombresDeRoles($this->usuario());
    }

    /**
     * Los cambios de rol quedan en el registro de actividad (quién, a quién,
     * antes y después).
     */
    protected function afterSave(): void
    {
        $usuario = $this->usuario();
        $usuario->unsetRelation('roles');

        UserResource::registrarCambioDeRoles($usuario, $this->rolesAntes, UserResource::nombresDeRoles($usuario));
    }

    private function usuario(): User
    {
        /** @var User $usuario */
        $usuario = $this->getRecord();

        return $usuario;
    }
}
