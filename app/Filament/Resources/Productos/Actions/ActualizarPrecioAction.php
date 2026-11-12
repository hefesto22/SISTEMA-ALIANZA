<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Actions;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Precios\ActualizarPrecioProducto;
use App\Domain\Precios\CalculadoraPrecio;
use App\Enums\MotivoCambioPrecio;
use App\Models\CambioPrecio;
use App\Models\Presentacion;
use App\Models\Producto;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * "Actualizar precio": se escriben el COSTO (de la presentación que se
 * escoja: la caja de 12, la unidad…) y el PRECIO DE VENTA de cada
 * presentación que se vende, tal como se manejan en la calle (con ISV si el
 * producto es gravado). El sistema saca lo demás: la utilidad (después del
 * impuesto), la ganancia y lo que se guarda sin ISV. Aplica a TODAS las
 * bodegas y deja historial.
 *
 * El precio de cada presentación puede ser calculado (costo promedio × lo
 * que trae + % de utilidad; se recalcula solo en cada compra) o fijo (lo
 * pone la empresa a mano, p. ej. para estar mejor que la competencia; las
 * compras cambian el costo pero no ese precio). Si se escribe un precio
 * distinto al calculado, queda fijo.
 *
 * Por dentro el costo se lleva POR UNIDAD BASE Y SIN ISV (costo_base) y el
 * precio de cada presentación SIN ISV (precio_base), con 6 decimales; lo
 * que se ve en pantalla sale de ahí. Así, al cambiar la presentación del
 * costo nada se descuadra.
 */
final class ActualizarPrecioAction
{
    private const CAMBIO_SOSPECHOSO = 0.25;

    private const CALCULADO = 'calculado';

    private const FIJO = 'fijo';

    /** Montos un poco más grandes y con números alineados. */
    private const ESTILO_MONTO = 'font-size: 1.05rem; font-weight: 600; font-variant-numeric: tabular-nums;';

    public static function make(): Action
    {
        return Action::make('actualizarPrecio')
            ->label('Actualizar precio')
            ->icon(Heroicon::OutlinedCurrencyDollar)
            ->color('warning')
            ->authorize('update')
            ->modalIcon(Heroicon::OutlinedCurrencyDollar)
            ->modalIconColor('warning')
            ->modalHeading(fn (Producto $record): string => $record->descripcion)
            ->modalDescription(fn (Producto $record): string => self::ficha($record))
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitActionLabel('Guardar precios')
            ->fillForm(function (Producto $record): array {
                $record->loadMissing(['categoria', 'presentaciones.unidadMedida']);
                $costoPor = self::presentaciones($record)[0] ?? $record->presentacionBase();

                return [
                    'costo_por'      => $costoPor->id,
                    'costo_base'     => $record->costo_unidad,
                    'costo'          => CalculadoraPrecio::aPantalla($record->costo_unidad, $record->gravado, $costoPor->contenido),
                    'presentaciones' => array_map(fn (Presentacion $presentacion): array => [
                        'id'          => $presentacion->id,
                        'etiqueta'    => $presentacion->etiqueta(),
                        'contenido'   => $presentacion->contenido,
                        'modo'        => $presentacion->precio_fijo ? self::FIJO : self::CALCULADO,
                        'precio_base' => $presentacion->precio,
                        'precio'      => $presentacion->precio > 0 ? round(CalculadoraPrecio::conIsv($presentacion->precio, $record->gravado), 2) : null,
                    ], self::presentaciones($record)),
                ];
            })
            ->schema([
                Grid::make(12)->schema([
                    Group::make([
                        Hidden::make('costo_base'),
                        Grid::make(['default' => 1, 'sm' => 2])->schema([
                            Select::make('costo_por')
                                ->label('El costo es por')
                                ->options(fn (Producto $record): array => collect(self::presentaciones($record))->mapWithKeys(fn (Presentacion $presentacion): array => [$presentacion->id => $presentacion->etiqueta()])->all())
                                ->selectablePlaceholder(false)
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set, Producto $record): void {
                                    if (is_numeric($get('costo_base'))) {
                                        $set('costo', CalculadoraPrecio::aPantalla((float) $get('costo_base'), $record->gravado, self::contenidoDe($get('costo_por'), $record)));
                                    }
                                }),
                            TextInput::make('costo')
                                ->label(fn (Producto $record): string => 'Costo'.($record->gravado ? ' con ISV' : ''))
                                ->numeric()
                                ->minValue(0)
                                ->prefix('L.')
                                ->required()
                                ->autofocus()
                                ->extraInputAttributes(['style' => self::ESTILO_MONTO])
                                ->live(debounce: 400)
                                ->afterStateUpdated(function (Get $get, Set $set, Producto $record): void {
                                    self::alCambiarCosto($get, $set, $record);
                                }),
                        ]),
                        Repeater::make('presentaciones')
                            ->label('Precio de venta de cada presentación')
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->table([
                                TableColumn::make('Presentación'),
                                TableColumn::make('Precio de venta'),
                                TableColumn::make('Cómo'),
                            ])
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('contenido'),
                                Hidden::make('precio_base'),
                                TextInput::make('etiqueta')
                                    ->disabled()
                                    ->dehydrated(false),
                                TextInput::make('precio')
                                    ->numeric()
                                    ->minValue(0.01)
                                    ->prefix('L.')
                                    ->required(fn (Get $get): bool => $get('modo') === self::FIJO)
                                    ->extraInputAttributes(['style' => self::ESTILO_MONTO])
                                    ->live(debounce: 400)
                                    ->afterStateUpdated(function (Get $get, Set $set, Producto $record): void {
                                        $venta = $get('precio');

                                        if (is_numeric($venta)) {
                                            $set('precio_base', self::sinIsv((float) $venta, $record));
                                        }

                                        // Un precio distinto al calculado lo puso la empresa: queda fijo.
                                        $sugerido = self::sugeridoEnPantalla($get('../../costo_base'), $get('contenido'), $record);

                                        if ($sugerido !== null && (! is_numeric($venta) || abs((float) $venta - $sugerido) >= 0.005)) {
                                            $set('modo', self::FIJO);
                                        }
                                    }),
                                ToggleButtons::make('modo')
                                    ->options([self::CALCULADO => 'Calculado', self::FIJO => 'Fijo'])
                                    ->icons([self::CALCULADO => Heroicon::OutlinedCalculator, self::FIJO => Heroicon::OutlinedLockClosed])
                                    ->grouped()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (?string $state, Get $get, Set $set, Producto $record): void {
                                        if ($state === self::CALCULADO) {
                                            self::ponerSugerido($get('../../costo_base'), $get, $set, $record);
                                        }
                                    }),
                            ]),
                        TextInput::make('nota')
                            ->label('Nota (opcional)')
                            ->placeholder('Ej: Factura 375448')
                            ->maxLength(255),
                    ])->columnSpan(7),

                    View::make('filament.productos.precio-resultado')
                        ->viewData(fn (Get $get, Producto $record): array => self::resultado($get, $record))
                        ->columnSpan(5),
                ]),
            ])
            ->action(function (array $data, Producto $record): void {
                /** @var User|null $usuario */
                $usuario = Auth::user();
                $record->loadMissing(['categoria', 'presentaciones.unidadMedida']);
                $antes = collect(self::presentaciones($record))->mapWithKeys(fn (Presentacion $presentacion): array => [$presentacion->id => $presentacion->precioConIsv()])->all();

                try {
                    $costo = self::costoAGuardar($data, $record);

                    $cambios = app(ActualizarPrecioProducto::class)->ejecutar(
                        $record,
                        $costo,
                        abs($costo - $record->costo_unidad) >= 0.00005 ? MotivoCambioPrecio::Compra : MotivoCambioPrecio::Manual,
                        $usuario,
                        self::preciosAGuardar($data, $record),
                        filled($data['nota'] ?? null) ? (string) $data['nota'] : null,
                    );
                } catch (ReglaNegocioException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                if ($cambios === []) {
                    Notification::make()
                        ->title('Se guardó sin cambios de precio')
                        ->body('El costo y los precios ya estaban así (si cambió algo entre fijo y calculado, ya quedó).')
                        ->info()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Precios actualizados en todas las bodegas')
                    ->body(collect($cambios)
                        ->filter(fn (CambioPrecio $cambio): bool => $cambio->presentacion !== null)
                        ->map(fn (CambioPrecio $cambio): string => $cambio->presentacion?->sigla().': '.lempiras($antes[$cambio->presentacion_id] ?? 0).' → '.lempiras(CalculadoraPrecio::conIsv($cambio->precio_nuevo, $record->gravado)))
                        ->implode(' · ').($record->gravado ? ' (con ISV).' : '.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Las que se venden, la más grande primero (si ninguna se vende, todas).
     *
     * @return list<Presentacion>
     */
    private static function presentaciones(Producto $producto): array
    {
        $activas = $producto->presentacionesActivas();
        $lista = $activas->isEmpty() ? $producto->presentaciones : $activas;

        return array_values($lista->sortByDesc('contenido')->all());
    }

    private static function contenidoDe(mixed $presentacionId, Producto $producto): int
    {
        $presentacion = $producto->presentaciones->first(fn (Presentacion $p): bool => $p->id === (is_numeric($presentacionId) ? (int) $presentacionId : 0));

        return $presentacion instanceof Presentacion ? $presentacion->contenido : 1;
    }

    /**
     * El costo por unidad base sin ISV que se guarda; si quedó igual que lo guardado, el guardado.
     *
     * @param array<string, mixed> $data
     */
    private static function costoAGuardar(array $data, Producto $producto): float
    {
        if (! is_numeric($data['costo'] ?? null) || ! is_numeric($data['costo_base'] ?? null) || (float) $data['costo_base'] < 0) {
            throw new ReglaNegocioException('Escriba el costo.');
        }

        $nuevo = round((float) $data['costo_base'], 4);

        return abs($nuevo - $producto->costo_unidad) < 0.00005 ? $producto->costo_unidad : $nuevo;
    }

    /**
     * Por presentación: las fijas con su precio (sin ISV) y las calculadas
     * sin precio (lo saca ActualizarPrecioProducto).
     *
     * @param array<string, mixed> $data
     *
     * @return array<int, array{precio: float|null, fijo: bool}>
     */
    private static function preciosAGuardar(array $data, Producto $producto): array
    {
        $precios = [];

        foreach (array_values((array) ($data['presentaciones'] ?? [])) as $fila) {
            if (! is_array($fila) || ! is_numeric($fila['id'] ?? null)) {
                continue;
            }

            $presentacion = $producto->presentaciones->first(fn (Presentacion $p): bool => $p->id === (int) $fila['id']);

            if (! $presentacion instanceof Presentacion) {
                continue;
            }

            if (($fila['modo'] ?? null) !== self::FIJO) {
                $precios[$presentacion->id] = ['precio' => null, 'fijo' => false];

                continue;
            }

            if (! is_numeric($fila['precio_base'] ?? null) || (float) $fila['precio_base'] <= 0) {
                throw new ReglaNegocioException("Escriba el precio de {$presentacion->sigla()}.");
            }

            $nuevo = round((float) $fila['precio_base'], 4);

            $precios[$presentacion->id] = [
                'precio' => abs($nuevo - $presentacion->precio) < 0.00005 ? $presentacion->precio : $nuevo,
                'fijo'   => true,
            ];
        }

        return $precios;
    }

    /**
     * Monto en pantalla (con ISV si es gravado) → sin ISV, 6 decimales.
     */
    private static function sinIsv(float $monto, Producto $producto): float
    {
        $isv = $producto->gravado ? 1 + CalculadoraPrecio::tasaIsv() : 1.0;

        return round($monto / $isv, 6);
    }

    /**
     * Se escribió un costo: se guarda por unidad base y las presentaciones
     * calculadas se recalculan con el % de utilidad. Las fijas se quedan.
     */
    private static function alCambiarCosto(Get $get, Set $set, Producto $producto): void
    {
        $costo = $get('costo');

        if (! is_numeric($costo)) {
            return;
        }

        $base = round(self::sinIsv((float) $costo, $producto) / max(1, self::contenidoDe($get('costo_por'), $producto)), 6);
        $set('costo_base', $base);

        $filas = $get('presentaciones');

        foreach (is_array($filas) ? $filas : [] as $clave => $fila) {
            if (is_array($fila) && ($fila['modo'] ?? null) !== self::FIJO) {
                $sugerido = self::precioSugerido($base, (int) ($fila['contenido'] ?? 1), $producto);

                if ($sugerido !== null) {
                    $set("presentaciones.{$clave}.precio_base", $sugerido);
                    $set("presentaciones.{$clave}.precio", round(CalculadoraPrecio::conIsv($sugerido, $producto->gravado), 2));
                }
            }
        }
    }

    /**
     * Pone en la fila el precio que da el % de utilidad con el costo escrito.
     */
    private static function ponerSugerido(mixed $costoBase, Get $get, Set $set, Producto $producto): void
    {
        $sugerido = is_numeric($costoBase) ? self::precioSugerido((float) $costoBase, (int) $get('contenido'), $producto) : null;

        if ($sugerido !== null) {
            $set('precio_base', $sugerido);
            $set('precio', round(CalculadoraPrecio::conIsv($sugerido, $producto->gravado), 2));
        }
    }

    /**
     * Precio sin ISV de la presentación que da el % de utilidad con ese costo por unidad.
     */
    private static function precioSugerido(float $costoBase, int $contenido, Producto $producto): ?float
    {
        if ($costoBase <= 0) {
            return null;
        }

        try {
            return CalculadoraPrecio::precioVenta(round($costoBase * max(1, $contenido), 4), $producto->margenAplicado());
        } catch (ReglaNegocioException) {
            return null;
        }
    }

    private static function sugeridoEnPantalla(mixed $costoBase, mixed $contenido, Producto $producto): ?float
    {
        $sugerido = is_numeric($costoBase) ? self::precioSugerido((float) $costoBase, is_numeric($contenido) ? (int) $contenido : 1, $producto) : null;

        return $sugerido === null ? null : round(CalculadoraPrecio::conIsv($sugerido, $producto->gravado), 2);
    }

    /**
     * "MERHONSA · Código 740100167992 · se cuenta en UN · gravado · utilidad 14%".
     */
    private static function ficha(Producto $producto): string
    {
        return collect([
            $producto->proveedor->etiqueta(),
            "Código {$producto->codigo}",
            'se cuenta en '.$producto->presentacionBase()->sigla(),
            $producto->gravado ? 'gravado (paga ISV)' : 'exento',
            'utilidad '.self::porcentaje($producto->margenAplicado()),
        ])->filter()->implode(' · ');
    }

    /**
     * Datos para la tarjeta de la derecha: por presentación, precio con ISV,
     * utilidad, ganancia y cuánto cambia contra hoy.
     *
     * @return array<string, mixed>
     */
    private static function resultado(Get $get, Producto $producto): array
    {
        $costoBase = $get('costo_base');

        if (! is_numeric($get('costo')) || ! is_numeric($costoBase)) {
            return ['mensaje' => 'Escriba el costo. La cuenta sale aquí.'];
        }

        $gravado = $producto->gravado;
        $costoHoy = CalculadoraPrecio::conIsv($producto->costo_unidad, $gravado);
        $costoNuevo = CalculadoraPrecio::conIsv(round((float) $costoBase, 4), $gravado);
        $costoExagerado = $costoHoy > 0 && abs($costoNuevo - $costoHoy) / $costoHoy > self::CAMBIO_SOSPECHOSO;
        $filas = [];
        $bajoCosto = false;
        $exagerado = false;

        foreach ((array) $get('presentaciones') as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $presentacion = $producto->presentaciones->first(fn (Presentacion $p): bool => $p->id === (is_numeric($fila['id'] ?? null) ? (int) $fila['id'] : 0));

            if (! $presentacion instanceof Presentacion) {
                continue;
            }

            $contenido = $presentacion->contenido;
            $costo = round((float) $costoBase * $contenido, 4);
            $precio = is_numeric($fila['precio_base'] ?? null) ? round((float) $fila['precio_base'], 4) : 0.0;
            $venta = CalculadoraPrecio::conIsv($precio, $gravado);
            $hoy = $presentacion->precioConIsv();
            $ganancia = $venta - CalculadoraPrecio::conIsv($costo, $gravado);
            $diferencia = round($venta - $hoy, 2);

            $bajoCosto = $bajoCosto || ($precio > 0 && $ganancia < 0);
            $exagerado = $exagerado || ($hoy > 0 && abs($diferencia) / $hoy > self::CAMBIO_SOSPECHOSO);

            $filas[] = [
                'sigla'    => $presentacion->sigla(),
                'nombre'   => $presentacion->nombre(),
                'venta'    => $precio > 0 ? lempiras($venta) : 'Sin precio',
                'sinIsv'   => lempiras($precio),
                'margen'   => self::porcentaje(CalculadoraPrecio::margenReal($costo, $precio)),
                'ganancia' => lempiras($ganancia),
                'hoy'      => $hoy > 0 ? lempiras($hoy) : '—',
                'tono'     => match (true) {
                    abs($diferencia) < 0.005 => 'igual',
                    $diferencia > 0          => 'sube',
                    default                  => 'baja',
                },
                'cambio' => abs($diferencia) < 0.005 ? 'igual' : ($diferencia > 0 ? '+' : '−').lempiras(abs($diferencia)),
                'fijo'   => ($fila['modo'] ?? null) === self::FIJO,
            ];
        }

        $avisos = array_filter([
            $bajoCosto ? 'Hay una presentación por debajo del costo.' : null,
            $exagerado || $costoExagerado
                ? 'Es un cambio muy grande contra hoy (costo '.lempiras($costoHoy, 4).' por '.$producto->presentacionBase()->sigla().'). Revise los montos y la presentación del costo.'
                : null,
        ]);

        return [
            'mensaje'   => null,
            'gravado'   => $gravado,
            'filas'     => $filas,
            'costo'     => lempiras($costoNuevo, 4),
            'costoNeto' => lempiras(round((float) $costoBase, 4), 4),
            'base'      => $producto->presentacionBase()->sigla(),
            'aviso'     => $avisos === [] ? null : implode(' ', $avisos),
        ];
    }

    /**
     * 0.14 → "14%", 0.1525 → "15.25%".
     */
    private static function porcentaje(float $fraccion): string
    {
        return rtrim(rtrim(number_format($fraccion * 100, 2), '0'), '.').'%';
    }
}
