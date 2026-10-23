<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Rol;
use App\Models\Ruta;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Información Personal')
                    ->description('Nombre, correo electrónico y datos de contacto del usuario.')
                    ->icon('heroicon-o-user')
                    ->aside()
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre completo')
                            ->required()
                            ->maxLength(255)
                            ->prefixIcon('heroicon-o-user')
                            ->placeholder('Ej: Juan Pérez'),
                        TextInput::make('email')
                            ->label('Correo electrónico')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->prefixIcon('heroicon-o-envelope')
                            ->placeholder('correo@ejemplo.com'),
                        TextInput::make('phone')
                            ->label('Teléfono')
                            ->tel()
                            ->maxLength(20)
                            ->prefixIcon('heroicon-o-phone')
                            ->placeholder('+504 9999-9999'),
                    ]),

                Section::make('Bodega y código')
                    ->description('Vendedores y encargados de bodega trabajan con los pedidos de su bodega. El código sirve para entrar sin escribir el correo.')
                    ->icon('heroicon-o-building-storefront')
                    ->aside()
                    ->schema([
                        Select::make('bodega_id')
                            ->label('Bodega')
                            ->relationship('bodega', 'nombre')
                            ->preload()
                            ->placeholder('Sin bodega (administración)'),
                        TextInput::make('codigo')
                            ->label('Código de usuario')
                            ->placeholder('Ej: HO01')
                            ->maxLength(20)
                            ->unique(ignoreRecord: true)
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? mb_strtoupper(trim((string) $state)) : null),
                    ]),

                Section::make('Foto de Perfil')
                    ->description('Imagen de avatar del usuario. Formato PNG o JPG, máximo 2MB.')
                    ->icon('heroicon-o-camera')
                    ->aside()
                    ->schema([
                        FileUpload::make('avatar_url')
                            ->label('')
                            ->image()
                            ->avatar()
                            ->directory('avatars')
                            ->maxSize(2048),
                    ]),

                Section::make('Seguridad')
                    ->description('Contraseña de acceso y estado de verificación del correo electrónico.')
                    ->icon('heroicon-o-lock-closed')
                    ->aside()
                    ->schema([
                        TextInput::make('password')
                            ->label('Contraseña')
                            ->password()
                            ->revealable()
                            ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->rule(Password::default())
                            ->prefixIcon('heroicon-o-key')
                            ->placeholder(
                                fn (string $operation): string => $operation === 'edit' ? 'Dejar vacío para mantener actual' : 'Mínimo 8 caracteres'
                            ),
                        DateTimePicker::make('email_verified_at')
                            ->label('Email verificado el')
                            ->prefixIcon('heroicon-o-check-badge')
                            ->placeholder('Sin verificar'),
                    ]),

                Section::make('Roles y Permisos')
                    ->description('Define qué puede hacer este usuario dentro del sistema.')
                    ->icon('heroicon-o-shield-check')
                    ->aside()
                    ->schema([
                        Select::make('roles')
                            ->label('Roles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->live()
                            ->placeholder('Seleccionar roles'),
                    ]),

                Section::make('Rutas')
                    ->description('Rutas que trabaja el vendedor. Solo ve y crea clientes de los municipios de esas rutas. Las rutas se arman en Administración → Rutas.')
                    ->icon('heroicon-o-map')
                    ->aside()
                    ->visible(fn (Get $get): bool => self::esVendedor($get('roles')))
                    ->schema([
                        Select::make('rutas')
                            ->label('Rutas')
                            ->relationship(
                                'rutas',
                                'nombre',
                                fn (Builder $query): Builder => $query->with('bodega')->orderBy('nombre'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Ruta $record): string => "{$record->nombre} · {$record->bodega->codigo}".($record->activa ? '' : ' (inactiva)'))
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->placeholder('Escoja sus rutas'),
                    ]),

                Section::make('Estado de la Cuenta')
                    ->description('Controla si el usuario puede acceder al sistema.')
                    ->icon('heroicon-o-power')
                    ->aside()
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Usuario activo')
                            ->default(true)
                            ->onColor('success')
                            ->offColor('danger')
                            ->helperText('Si se desactiva, el usuario no podrá iniciar sesión en el panel.'),
                    ]),
            ]);
    }

    /**
     * Las rutas solo aplican si entre los roles escogidos está el de vendedor.
     */
    private static function esVendedor(mixed $roles): bool
    {
        if (! is_array($roles) || $roles === []) {
            return false;
        }

        $vendedor = Role::query()->where('name', Rol::Vendedor->value)->value('id');

        return $vendedor !== null && in_array((string) $vendedor, array_map(strval(...), $roles), true);
    }
}
