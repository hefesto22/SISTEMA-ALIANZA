<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Schemas\UserInfolist;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Usuario';

    protected static ?string $pluralModelLabel = 'Usuarios';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Sistema';
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    /**
     * Filtrar la query base: cada usuario solo ve su rama.
     * Super admin ve todos.
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = Auth::user();

        return parent::getEloquentQuery()->visibleTo($user);
    }

    /**
     * Ver: el permiso (UserPolicy) y que sea de su rama. Super admin, todos.
     */
    public static function canView(Model $record): bool
    {
        return parent::canView($record) && self::esDeSuRama($record);
    }

    /**
     * Editar: el permiso y que sea de su rama. A un super admin solo lo
     * edita otro super admin.
     */
    public static function canEdit(Model $record): bool
    {
        return parent::canEdit($record) && self::esDeSuRama($record) && self::puedeTocarSuperAdmin($record);
    }

    /**
     * Eliminar: el permiso (que ya impide eliminarse a sí mismo, a un super
     * admin o a quien tiene pedidos) y que sea de su rama.
     */
    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record) && self::esDeSuRama($record);
    }

    /**
     * @return list<string>
     */
    public static function nombresDeRoles(User $usuario): array
    {
        /** @var list<string> $nombres */
        $nombres = $usuario->roles()->orderBy('name')->pluck('name')->values()->all();

        return $nombres;
    }

    /**
     * Deja en el registro de actividad quién le cambió los roles a quién.
     *
     * @param list<string> $antes
     * @param list<string> $despues
     */
    public static function registrarCambioDeRoles(User $usuario, array $antes, array $despues): void
    {
        if ($antes === $despues) {
            return;
        }

        activity('usuarios')
            ->performedOn($usuario)
            ->causedBy(Auth::user())
            ->event('roles')
            ->withProperties(['antes' => $antes, 'despues' => $despues])
            ->log('Roles: '.(implode(', ', $antes) ?: 'ninguno').' → '.(implode(', ', $despues) ?: 'ninguno'));
    }

    private static function esDeSuRama(Model $record): bool
    {
        /** @var User $user */
        $user = Auth::user();

        return $user->esSuperAdmin() || in_array($record->getKey(), $user->getVisibleUserIds());
    }

    private static function puedeTocarSuperAdmin(Model $record): bool
    {
        /** @var User $user */
        $user = Auth::user();

        return $user->esSuperAdmin() || ! ($record instanceof User && $record->esSuperAdmin());
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view'   => ViewUser::route('/{record}'),
            'edit'   => EditUser::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email'];
    }
}
