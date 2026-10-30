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
 * - Nombres repetidos: sin RTN no se puede; con RTN solo en otro municipio;
 *   el mismo RTN con otro nombre sí (Cliente::conElMismoNombre).
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
            RTNField::make()
                ->helperText('14 dígitos, sin guiones. Si el cliente no tiene RTN, su nombre no se puede repetir.'),
            TelefonoHondurasField::make('telefono', 'Teléfono'),
            ...UbicacionFields::make(soloSusRutas: true),
            TextInput::make('direccion')
                ->label('Dirección / referencia')
                ->placeholder('Ej. Barrio El Centro, frente al parque')
                ->helperText('Barrio o aldea, calle y algo cercano que ayude a encontrarlo.')
                ->maxLength(255)
                ->columnSpanFull(),
            Select::make('bodega_id')
                ->label('Bodega')
                ->relationship('bodega', 'nombre')
                ->preload()
                ->required()
                ->visible(Acceso::veTodo()),
            // Un cliente nuevo siempre está activo; se desactiva al editarlo.
            Toggle::make('activo')
                ->default(true)
                ->inline(false)
                ->visibleOn('edit'),
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
     * No deja registrar dos veces el mismo cliente (Cliente::conElMismoNombre).
     *
     * Al editar solo se revisa si cambió el nombre, el RTN o el municipio:
     * hay repetidos de antes de esta regla y no deben estorbar para cambiarles
     * el teléfono o la ubicación.
     */
    private static function noRepetido(Get $get, ?Model $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
            if (! is_string($value)) {
                return;
            }

            $rtn = self::texto($get('rtn'));
            $municipio = is_numeric($get('municipio_id')) ? (int) $get('municipio_id') : null;
            $actual = $record instanceof Cliente ? $record : null;

            if ($actual !== null
                && Texto::clave($actual->nombre) === Texto::clave($value)
                && self::texto($actual->rtn) === $rtn
                && ($actual->municipio_id === null ? null : (int) $actual->municipio_id) === $municipio) {
                return;
            }

            $otro = Cliente::conElMismoNombre($value, $rtn, $municipio, $actual?->id);

            if ($otro === null) {
                return;
            }

            $fail($rtn === ''
                ? "Ya hay un cliente con este nombre: {$otro->etiqueta()}. Sin RTN el nombre no se puede repetir: escriba el RTN o agregue algo que lo distinga, como el barrio o la aldea."
                : "Ya hay un cliente con este nombre en ese municipio: {$otro->etiqueta()}. En el mismo municipio no se puede repetir: agregue algo que lo distinga, como el barrio o la colonia.");
        };
    }

    private static function texto(mixed $valor): string
    {
        return is_string($valor) ? trim($valor) : '';
    }
}
