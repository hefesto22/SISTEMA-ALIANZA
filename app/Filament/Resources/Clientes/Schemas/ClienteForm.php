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
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

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
     * Pensado para el teléfono: RTN y teléfono lado a lado, el municipio en
     * un solo campo (se busca por municipio o departamento) y sin textos de
     * ayuda que alarguen el formulario; lo que hace falta lo dice el error.
     *
     * @return array<int, Component>
     */
    public static function components(): array
    {
        return [
            Grid::make(['default' => 2])
                ->columnSpanFull()
                ->schema([
                    TextInput::make('nombre')
                        ->label('Nombre del negocio')
                        ->placeholder('Ej. Pulpería La Bendición')
                        ->required()
                        ->maxLength(255)
                        ->autocomplete(false)
                        // Al salir del campo avisa si ya hay uno que se llama igual,
                        // antes de llenar lo demás.
                        ->live(onBlur: true)
                        ->helperText(fn (mixed $state, ?Model $record): ?HtmlString => self::avisoDeNombre($state, $record))
                        ->rule(fn (Get $get, ?Model $record): Closure => self::noRepetido($get, $record))
                        ->columnSpanFull(),
                    RTNField::make()
                        ->placeholder('Opcional')
                        ->helperText(null)
                        ->validationMessages([
                            'size'  => 'El RTN lleva 14 dígitos.',
                            'regex' => 'El RTN lleva 14 dígitos, sin guiones.',
                        ]),
                    TelefonoHondurasField::make('telefono', 'Teléfono')
                        ->helperText(null)
                        ->validationMessages([
                            'regex' => '8 dígitos que empiecen con 2, 3 o 9.',
                            'min'   => '8 dígitos que empiecen con 2, 3 o 9.',
                        ]),
                    UbicacionFields::municipio(soloSusRutas: true)
                        ->columnSpanFull(),
                    TextInput::make('direccion')
                        ->label('Referencia')
                        ->placeholder('Barrio, calle y algo cercano')
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Select::make('bodega_id')
                        ->label('Bodega')
                        ->relationship('bodega', 'nombre')
                        ->preload()
                        ->required()
                        ->visible(Acceso::veTodo())
                        ->columnSpanFull(),
                    // Un cliente nuevo siempre está activo; se desactiva al editarlo.
                    Toggle::make('activo')
                        ->default(true)
                        ->inline(false)
                        ->visibleOn('edit')
                        ->columnSpanFull(),
                ]),
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
     * "Ya hay un cliente con este nombre: …" debajo del nombre, apenas se
     * escribe. Solo avisa; la regla se aplica al guardar (noRepetido).
     */
    public static function avisoDeNombre(mixed $nombre, ?Model $record): ?HtmlString
    {
        $clave = Texto::clave(self::texto($nombre));

        if ($clave === '') {
            return null;
        }

        $query = Cliente::query()
            ->with('municipio.departamento')
            ->where('nombre_clave', $clave)
            ->orderBy('id');

        if ($record instanceof Cliente) {
            $query->whereKeyNot($record->getKey());
        }

        $otro = $query->first();

        if ($otro === null) {
            return null;
        }

        return new HtmlString('<span style="color: var(--warning-600)">'
            .e("Ya hay un cliente con este nombre: {$otro->etiqueta()}. Si es otro negocio, ponga su RTN o agregue el barrio al nombre.")
            .'</span>');
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
