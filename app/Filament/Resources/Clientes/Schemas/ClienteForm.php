<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes\Schemas;

use App\Enums\Rol;
use App\Filament\Schemas\Components\RTNField;
use App\Filament\Schemas\Components\TelefonoHondurasField;
use App\Filament\Support\Acceso;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Builder;

/**
 * Campos del cliente. Se usan en Clientes y al crear un cliente nuevo
 * directo desde el pedido (el vendedor en ruta).
 */
class ClienteForm
{
    /**
     * @return array<int, Component>
     */
    public static function components(): array
    {
        $veTodo = Acceso::veTodo();

        return [
            TextInput::make('nombre')
                ->label('Nombre del negocio / cliente')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            TextInput::make('codigo')
                ->label('Código')
                ->maxLength(30)
                ->unique(ignoreRecord: true),
            RTNField::make(),
            TelefonoHondurasField::make('telefono', 'Teléfono'),
            TextInput::make('direccion')
                ->label('Dirección / referencia')
                ->maxLength(255),
            Select::make('bodega_id')
                ->label('Bodega')
                ->relationship('bodega', 'nombre')
                ->preload()
                ->required()
                ->visible($veTodo),
            Select::make('vendedor_id')
                ->label('Vendedor de la ruta')
                ->relationship('vendedor', 'name', fn (Builder $query): Builder => $query->whereHas('roles', fn (Builder $q): Builder => $q->where('name', Rol::Vendedor->value)))
                ->searchable()
                ->preload()
                ->visible(! Acceso::esVendedor()),
            Toggle::make('activo')
                ->default(true),
        ];
    }

    /**
     * Si quien crea no es administración, el cliente queda en su bodega
     * y, si es vendedor, a su nombre.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function completar(array $data): array
    {
        $usuario = Acceso::usuario();

        if ($usuario === null || $usuario->veTodasLasBodegas()) {
            return $data;
        }

        $data['bodega_id'] = $usuario->bodega_id;

        if ($usuario->esVendedor()) {
            $data['vendedor_id'] = $usuario->id;
        }

        return $data;
    }
}
