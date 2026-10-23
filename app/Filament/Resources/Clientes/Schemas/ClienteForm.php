<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes\Schemas;

use App\Filament\Schemas\Components\RTNField;
use App\Filament\Schemas\Components\TelefonoHondurasField;
use App\Filament\Schemas\Components\UbicacionFields;
use App\Filament\Support\Acceso;
use App\Models\Cliente;
use App\Support\Texto;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;

/**
 * Campos del cliente. Se usan en Clientes y al crear un cliente nuevo
 * directo desde el pedido (el vendedor en ruta).
 *
 * - El código (CLI-000001) se genera solo.
 * - El vendedor solo puede escoger municipios de sus rutas: así el cliente
 *   que crea le sigue apareciendo a él.
 * - Puede repetirse nombre y RTN en municipios distintos (sucursales), pero
 *   no en el mismo municipio: ahí sería el mismo cliente dos veces.
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
                ->rule(fn (Get $get, ?Model $record): Closure => self::noRepetido($get, $record))
                ->columnSpanFull(),
            RTNField::make(),
            TelefonoHondurasField::make('telefono', 'Teléfono'),
            ...UbicacionFields::make(soloSusRutas: true),
            TextInput::make('direccion')
                ->label('Dirección / referencia')
                ->placeholder('Ej. Barrio El Centro, frente al parque')
                ->maxLength(255)
                ->columnSpanFull(),
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

    /**
     * Mismo nombre + mismo RTN + mismo municipio = el cliente ya existe.
     * Sin RTN no se bloquea: hay negocios distintos que se llaman igual.
     */
    private static function noRepetido(Get $get, ?Model $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
            $rtn = $get('rtn');
            $municipio = $get('municipio_id');

            if (! is_string($value) || blank($rtn) || blank($municipio)) {
                return;
            }

            $query = Cliente::query()
                ->where('nombre', Texto::mayusculas($value))
                ->where('rtn', $rtn)
                ->where('municipio_id', $municipio);

            if ($record instanceof Cliente) {
                $query->whereKeyNot($record->getKey());
            }

            $existente = $query->first();

            if ($existente instanceof Cliente) {
                $fail("Este cliente ya existe en ese municipio con el mismo RTN ({$existente->codigo}).");
            }
        };
    }
}
