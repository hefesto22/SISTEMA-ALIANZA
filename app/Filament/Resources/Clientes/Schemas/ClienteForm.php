<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes\Schemas;

use App\Filament\Schemas\Components\RTNField;
use App\Filament\Schemas\Components\TelefonoHondurasField;
use App\Filament\Schemas\Components\UbicacionFields;
use App\Filament\Support\Acceso;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;

/**
 * Campos del cliente. Se usan en Clientes y al crear un cliente nuevo
 * directo desde el pedido (el vendedor en ruta).
 *
 * El vendedor solo puede escoger municipios de sus rutas: así el cliente
 * que crea le sigue apareciendo a él.
 */
class ClienteForm
{
    /**
     * @return array<int, Component>
     */
    public static function components(): array
    {
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
            ...UbicacionFields::make(soloSusRutas: true),
            TelefonoHondurasField::make('telefono', 'Teléfono'),
            TextInput::make('direccion')
                ->label('Dirección / referencia')
                ->placeholder('Ej. Barrio El Centro, frente al parque')
                ->maxLength(255),
            Select::make('bodega_id')
                ->label('Bodega')
                ->relationship('bodega', 'nombre')
                ->preload()
                ->required()
                ->visible(Acceso::veTodo()),
            Toggle::make('activo')
                ->default(true)
                ->inline(false),
        ];
    }

    /**
     * Si quien crea no es administración, el cliente queda en su bodega.
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

        return $data;
    }
}
