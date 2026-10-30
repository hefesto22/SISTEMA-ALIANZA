<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes\Schemas;

use App\Filament\Schemas\Components\RTNField;
use App\Filament\Schemas\Components\TelefonoHondurasField;
use App\Filament\Schemas\Components\UbicacionEnMapa;
use App\Filament\Schemas\Components\UbicacionFields;
use App\Filament\Support\Acceso;
use App\Models\Cliente;
use App\Support\Mapa\BuscarLugar;
use App\Support\Texto;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
 * - Ubicación: se marca en el mapa (la actual del teléfono o tocando el
 *   mapa) y eso llena departamento, municipio y la referencia.
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
            UbicacionEnMapa::make('ubicacion')
                ->alMarcar(function (array $punto, Get $get, Set $set): void {
                    self::llenarConElMapa($punto, $get, $set);
                })
                ->columnSpanFull(),
            ...UbicacionFields::make(soloSusRutas: true),
            TextInput::make('direccion')
                ->label('Dirección / referencia')
                ->placeholder('Ej. Barrio El Centro, frente al parque')
                ->helperText('Se llena con el mapa; agregue lo que ayude a encontrarlo.')
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
            // La referencia que puso el mapa la última vez: si el usuario no
            // la ha cambiado, al mover el pin se pone la nueva.
            Hidden::make('direccion_del_mapa')
                ->dehydrated(false),
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

    /**
     * Se marcó un punto en el mapa: pone departamento, municipio y la
     * referencia de ese punto. La ubicación se guarda aunque no se encuentre
     * la dirección.
     *
     * @param array<mixed> $punto {lat, lng}
     */
    private static function llenarConElMapa(array $punto, Get $get, Set $set): void
    {
        $lat = $punto['lat'] ?? null;
        $lng = $punto['lng'] ?? null;

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return;
        }

        $lugar = app(BuscarLugar::class)->en((float) $lat, (float) $lng);

        if ($lugar === null) {
            self::avisar('No se pudo leer la dirección de ese punto', 'La ubicación sí queda guardada. Escoja el departamento y el municipio, y escriba la referencia.');

            return;
        }

        if (! $lugar->enHonduras) {
            self::avisar('Ese punto no queda en Honduras', 'Mueva el pin al lugar donde está el negocio.');

            return;
        }

        // El vendedor solo registra clientes de sus rutas: un punto en otro
        // municipio casi siempre es el pin mal puesto.
        $permitidos = Acceso::usuario()?->municipiosDeRuta();

        if ($lugar->municipioId !== null && $permitidos !== null && ! in_array($lugar->municipioId, $permitidos, true)) {
            self::avisar("Ese punto queda en {$lugar->descripcion}, que no está en sus rutas", 'Revise el pin. Si el cliente es de otra ruta, pida a administración que lo registre.');

            return;
        }

        // La referencia se pone si está vacía o si todavía es la que puso el mapa.
        $actual = Texto::mayusculas(self::texto($get('direccion')));

        if ($lugar->referencia !== '' && ($actual === '' || $actual === self::texto($get('direccion_del_mapa')))) {
            $set('direccion', $lugar->referencia);
        }

        $set('direccion_del_mapa', $lugar->referencia);

        if ($lugar->municipioId === null || $lugar->departamentoId === null) {
            self::avisar('No se encontró el municipio de ese punto', "El mapa dice: {$lugar->descripcion}. Escoja el departamento y el municipio de la lista.");

            return;
        }

        $set('departamento_id', $lugar->departamentoId);
        $set('municipio_id', $lugar->municipioId);
    }

    private static function avisar(string $titulo, string $detalle): void
    {
        Notification::make()
            ->warning()
            ->title($titulo)
            ->body($detalle)
            ->send();
    }

    private static function texto(mixed $valor): string
    {
        return is_string($valor) ? trim($valor) : '';
    }
}
