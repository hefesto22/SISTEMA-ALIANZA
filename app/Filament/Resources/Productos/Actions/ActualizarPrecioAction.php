<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Actions;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Precios\ActualizarPrecioProducto;
use App\Domain\Precios\CalculadoraPrecio;
use App\Enums\MotivoCambioPrecio;
use App\Models\CambioPrecio;
use App\Models\CompraItem;
use App\Models\Existencia;
use App\Models\Presentacion;
use App\Models\Producto;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * "Actualizar precio": aquí se pone el PRECIO DE VENTA de cada presentación.
 *
 * El costo no se escribe: es el promedio ponderado que van dejando las
 * compras (o el de la última, según Configuración) y arriba se ve junto con
 * la última compra y la existencia. Solo si está mal se corrige ("Corregir
 * el costo").
 *
 * Cada presentación se vende:
 *  - Con %: el precio sale del costo × lo que trae con su % de utilidad
 *    (el de la presentación o, si no tiene, el del producto o su
 *    categoría) y se recalcula solo en cada compra: si compran barato, el
 *    precio baja y la ganancia en % se mantiene. La caja puede dejar menos
 *    que la unidad para estar mejor que la competencia.
 *  - Fijo: el precio lo pone la empresa; las compras cambian el costo pero
 *    no ese precio. Se ve cuánto gana de verdad, cuánto sería con su % y,
 *    en rojo, si queda bajo el costo.
 *
 * Escribir un % lo deja "Con %"; escribir un precio distinto al que da el %
 * lo deja "Fijo". Montos en pantalla con ISV si el producto es gravado; se
 * guardan sin ISV por ActualizarPrecioProducto (historial, todas las bodegas).
 */
final class ActualizarPrecioAction
{
    private const CAMBIO_SOSPECHOSO = 0.25;

    private const CALCULADO = 'calculado';

    private const FIJO = 'fijo';

    /** Montos un poco más grandes y con números alineados. */
    private const ESTILO_MONTO = 'font-weight: 600; font-variant-numeric: tabular-nums;';

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
            ->fillForm(fn (Producto $record): array => self::datosIniciales($record))
            ->schema([
                View::make('filament.productos.precio-resultado')
                    ->viewData(fn (Get $get, Producto $record): array => self::resumen($get, $record)),

                Toggle::make('corregir_costo')
                    ->label('Corregir el costo')
                    ->helperText('Solo si está mal: el costo lo ponen las compras.')
                    ->live()
                    ->afterStateUpdated(function (?bool $state, Get $get, Set $set, Producto $record): void {
                        // Al apagarlo, todo regresa al costo que ya tiene.
                        if (! $state) {
                            $set('costo_base', $record->costo_unidad);
                            $set('costo', CalculadoraPrecio::aPantalla($record->costo_unidad, $record->gravado, self::contenidoDe($get('costo_por'), $record)));
                            self::refrescarFilas($get, $set, $record, $record->costo_unidad);
                        }
                    }),

                Hidden::make('costo_base'),

                Grid::make(['default' => 1, 'sm' => 2])
                    ->visible(fn (Get $get): bool => (bool) $get('corregir_costo'))
                    ->schema([
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
                            ->label(fn (Producto $record): string => 'Costo correcto'.($record->gravado ? ' con ISV' : ''))
                            ->numeric()
                            ->minValue(0)
                            ->prefix('L.')
                            ->required()
                            ->extraInputAttributes(['style' => self::ESTILO_MONTO])
                            ->live(debounce: 400)
                            ->afterStateUpdated(function (Get $get, Set $set, Producto $record): void {
                                $costo = $get('costo');

                                if (is_numeric($costo)) {
                                    $base = round(self::sinIsv((float) $costo, $record) / max(1, self::contenidoDe($get('costo_por'), $record)), 6);
                                    $set('costo_base', $base);
                                    self::refrescarFilas($get, $set, $record, $base);
                                }
                            }),
                    ]),

                Repeater::make('presentaciones')
                    ->label('Precio de venta de cada presentación')
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->compact()
                    ->table(fn (Producto $record): array => [
                        TableColumn::make('Presentación'),
                        TableColumn::make($record->gravado ? 'Costo con ISV' : 'Costo')->width('9rem'),
                        TableColumn::make('Utilidad')->width('7.5rem'),
                        TableColumn::make($record->gravado ? 'Precio con ISV' : 'Precio')->width('12rem'),
                        TableColumn::make('Cómo')->width('11rem'),
                    ])
                    ->schema([
                        Hidden::make('id'),
                        Hidden::make('contenido'),
                        Hidden::make('precio_base'),
                        // El % con que se calcula (el suyo o el del producto): a ese regresa al pasar a "Con %".
                        Hidden::make('margen_regla'),
                        TextInput::make('etiqueta')
                            ->hiddenLabel()
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('costo')
                            ->hiddenLabel()
                            ->prefix('L.')
                            ->placeholder('Sin costo')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('margen')
                            ->label('Utilidad')
                            ->hiddenLabel()
                            ->numeric()
                            ->suffix('%')
                            ->required(fn (Get $get): bool => $get('modo') !== self::FIJO)
                            ->live(debounce: 500)
                            ->afterStateUpdated(function (Get $get, Set $set, Producto $record): void {
                                if (! is_numeric($get('margen'))) {
                                    return;
                                }

                                // Escribir un % es venderla "Con %": el precio sale de ahí.
                                $set('modo', self::CALCULADO);
                                $set('margen_regla', round((float) $get('margen'), 2));
                                self::ponerCalculado($get, $set, $record);
                            }),
                        TextInput::make('precio')
                            ->label('Precio')
                            ->hiddenLabel()
                            ->numeric()
                            ->minValue(0.01)
                            ->prefix('L.')
                            ->required(fn (Get $get): bool => $get('modo') === self::FIJO)
                            ->extraInputAttributes(['style' => self::ESTILO_MONTO])
                            ->helperText(fn (Get $get, Producto $record): Htmlable => self::ganancia($get, $record))
                            ->live(debounce: 500)
                            ->afterStateUpdated(function (Get $get, Set $set, Producto $record): void {
                                $venta = $get('precio');

                                if (! is_numeric($venta)) {
                                    return;
                                }

                                $set('precio_base', self::sinIsv((float) $venta, $record));

                                // Si es el mismo que da su %, sigue "Con %"; si es otro, lo puso la empresa: queda fijo.
                                $sugerido = self::sugeridoEnPantalla($get('../../costo_base'), $get('contenido'), $get('margen_regla'), $record);

                                if ($get('modo') !== self::FIJO && $sugerido !== null && abs((float) $venta - $sugerido) < 0.005) {
                                    return;
                                }

                                $set('modo', self::FIJO);
                                $set('margen', self::margenReal($get('../../costo_base'), $get('contenido'), $get('precio_base')));
                            }),
                        ToggleButtons::make('modo')
                            ->hiddenLabel()
                            ->options([self::CALCULADO => 'Con %', self::FIJO => 'Fijo'])
                            ->icons([self::CALCULADO => Heroicon::OutlinedCalculator, self::FIJO => Heroicon::OutlinedLockClosed])
                            ->grouped()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (?string $state, Get $get, Set $set, Producto $record): void {
                                if ($state === self::CALCULADO) {
                                    $set('margen', $get('margen_regla'));
                                    self::ponerCalculado($get, $set, $record);

                                    return;
                                }

                                $set('margen', self::margenReal($get('../../costo_base'), $get('contenido'), $get('precio_base')));
                            }),
                    ]),

                TextInput::make('nota')
                    ->label('Nota (opcional)')
                    ->placeholder('Ej: precio de la competencia')
                    ->maxLength(255),
            ])
            ->action(function (array $data, Producto $record): void {
                /** @var User|null $usuario */
                $usuario = Auth::user();
                $record->loadMissing(['categoria', 'presentaciones.unidadMedida']);
                $antes = collect(self::presentaciones($record))->mapWithKeys(fn (Presentacion $presentacion): array => [$presentacion->id => $presentacion->precioConIsv()])->all();

                try {
                    $cambios = app(ActualizarPrecioProducto::class)->ejecutar(
                        $record,
                        self::costoAGuardar($data, $record),
                        MotivoCambioPrecio::Manual,
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
                        ->body('Los precios ya estaban así (si cambió algo entre "Con %" y "Fijo", ya quedó).')
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
     * Lo que trae el formulario al abrir: el costo de hoy (por la
     * presentación más grande) y una fila por presentación.
     *
     * @return array<string, mixed>
     */
    private static function datosIniciales(Producto $producto): array
    {
        $producto->loadMissing(['categoria', 'presentaciones.unidadMedida']);
        $lista = self::presentaciones($producto);
        $costoPor = $lista[0] ?? $producto->presentacionBase();

        return [
            'corregir_costo' => false,
            'costo_por'      => $costoPor->id,
            'costo_base'     => $producto->costo_unidad,
            'costo'          => CalculadoraPrecio::aPantalla($producto->costo_unidad, $producto->gravado, $costoPor->contenido),
            'presentaciones' => array_map(fn (Presentacion $presentacion): array => self::fila($presentacion, $producto), $lista),
        ];
    }

    /**
     * Una fila de la tabla: costo y precio en pantalla, cómo se vende y su %
     * ("Con %": el que usa; "Fijo": el que de verdad deja).
     *
     * @return array<string, mixed>
     */
    private static function fila(Presentacion $presentacion, Producto $producto): array
    {
        $regla = round(($presentacion->margen ?? $producto->margenAplicado()) * 100, 2);
        $fijo = $presentacion->precio_fijo;

        return [
            'id'           => $presentacion->id,
            'etiqueta'     => $presentacion->etiqueta(),
            'contenido'    => $presentacion->contenido,
            'margen_regla' => $regla,
            'modo'         => $fijo ? self::FIJO : self::CALCULADO,
            'precio_base'  => $presentacion->precio,
            'precio'       => $presentacion->precio > 0 ? round(CalculadoraPrecio::conIsv($presentacion->precio, $producto->gravado), 2) : null,
            'costo'        => self::costoEnPantalla($producto->costo_unidad, $presentacion->contenido, $producto),
            'margen'       => $fijo ? self::margenReal($producto->costo_unidad, $presentacion->contenido, $presentacion->precio) : $regla,
        ];
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
     * El costo por unidad base sin ISV que se guarda: el de hoy, salvo que se haya corregido.
     *
     * @param array<string, mixed> $data
     */
    private static function costoAGuardar(array $data, Producto $producto): float
    {
        if (! ($data['corregir_costo'] ?? false)) {
            return $producto->costo_unidad;
        }

        if (! is_numeric($data['costo_base'] ?? null) || (float) $data['costo_base'] < 0) {
            throw new ReglaNegocioException('Escriba el costo correcto.');
        }

        $nuevo = round((float) $data['costo_base'], 4);

        return abs($nuevo - $producto->costo_unidad) < 0.00005 ? $producto->costo_unidad : $nuevo;
    }

    /**
     * Por presentación: las "Con %" con su % (null si es el mismo del
     * producto) y sin precio (lo saca ActualizarPrecioProducto); las fijas
     * con su precio sin ISV.
     *
     * @param array<string, mixed> $data
     *
     * @return array<int, array{precio: float|null, fijo: bool, margen?: float|null}>
     */
    private static function preciosAGuardar(array $data, Producto $producto): array
    {
        $precios = [];
        $delProducto = $producto->margenAplicado();

        foreach (array_values((array) ($data['presentaciones'] ?? [])) as $fila) {
            if (! is_array($fila) || ! is_numeric($fila['id'] ?? null)) {
                continue;
            }

            $presentacion = $producto->presentaciones->first(fn (Presentacion $p): bool => $p->id === (int) $fila['id']);

            if (! $presentacion instanceof Presentacion) {
                continue;
            }

            if (($fila['modo'] ?? null) !== self::FIJO) {
                if (! is_numeric($fila['margen'] ?? null)) {
                    throw new ReglaNegocioException("Escriba la utilidad de {$presentacion->sigla()}.");
                }

                $margen = round((float) $fila['margen'] / 100, 4);

                if ($margen < 0 || $margen >= 1) {
                    throw new ReglaNegocioException("La utilidad de {$presentacion->sigla()} debe ser de 0 % a 99.99 %.");
                }

                $precios[$presentacion->id] = [
                    'precio' => null,
                    'fijo'   => false,
                    'margen' => abs($margen - $delProducto) < 0.00005 ? null : $margen,
                ];

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

    private static function costoEnPantalla(float $costoBase, int $contenido, Producto $producto): ?float
    {
        return $costoBase > 0 ? CalculadoraPrecio::aPantalla($costoBase, $producto->gravado, $contenido) : null;
    }

    /**
     * Se corrigió el costo: cada fila muestra su costo nuevo; las "Con %" se
     * recalculan con su % y las fijas muestran lo que ahora dejan.
     */
    private static function refrescarFilas(Get $get, Set $set, Producto $producto, float $costoBase): void
    {
        $filas = $get('presentaciones');

        foreach (is_array($filas) ? $filas : [] as $clave => $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $contenido = is_numeric($fila['contenido'] ?? null) ? (int) $fila['contenido'] : 1;
            $set("presentaciones.{$clave}.costo", self::costoEnPantalla($costoBase, $contenido, $producto));

            if (($fila['modo'] ?? null) === self::FIJO) {
                $set("presentaciones.{$clave}.margen", self::margenReal($costoBase, $contenido, $fila['precio_base'] ?? null));

                continue;
            }

            $sugerido = self::precioSugerido($costoBase, $contenido, $fila['margen'] ?? null);

            if ($sugerido !== null) {
                $set("presentaciones.{$clave}.precio_base", $sugerido);
                $set("presentaciones.{$clave}.precio", round(CalculadoraPrecio::conIsv($sugerido, $producto->gravado), 2));
            }
        }
    }

    /**
     * Pone en la fila el precio que da su % con el costo de hoy (sin costo, se queda el que tiene).
     */
    private static function ponerCalculado(Get $get, Set $set, Producto $producto): void
    {
        $costoBase = $get('../../costo_base');
        $sugerido = is_numeric($costoBase) ? self::precioSugerido((float) $costoBase, (int) $get('contenido'), $get('margen')) : null;

        if ($sugerido !== null) {
            $set('precio_base', $sugerido);
            $set('precio', round(CalculadoraPrecio::conIsv($sugerido, $producto->gravado), 2));
        }
    }

    /**
     * Precio sin ISV de la presentación con ese costo por unidad y ese % (14 = 14 %); null si no se puede.
     */
    private static function precioSugerido(float $costoBase, int $contenido, mixed $margen): ?float
    {
        if ($costoBase <= 0 || ! is_numeric($margen) || (float) $margen < 0 || (float) $margen >= 100) {
            return null;
        }

        return CalculadoraPrecio::precioVenta(round($costoBase * max(1, $contenido), 4), round((float) $margen / 100, 4));
    }

    private static function sugeridoEnPantalla(mixed $costoBase, mixed $contenido, mixed $margen, Producto $producto): ?float
    {
        $sugerido = is_numeric($costoBase) ? self::precioSugerido((float) $costoBase, is_numeric($contenido) ? (int) $contenido : 1, $margen) : null;

        return $sugerido === null ? null : round(CalculadoraPrecio::conIsv($sugerido, $producto->gravado), 2);
    }

    /**
     * El % que de verdad deja ese precio con ese costo (14.5 = 14.5 %); null sin costo o sin precio.
     */
    private static function margenReal(mixed $costoBase, mixed $contenido, mixed $precio): ?float
    {
        if (! is_numeric($costoBase) || (float) $costoBase <= 0 || ! is_numeric($precio) || (float) $precio <= 0) {
            return null;
        }

        $costo = round((float) $costoBase * max(1, is_numeric($contenido) ? (int) $contenido : 1), 4);

        return round(CalculadoraPrecio::margenReal($costo, round((float) $precio, 4)) * 100, 2);
    }

    /**
     * Debajo del precio: cuánto gana (o pierde) por presentación y, si es
     * fija, cuánto sería con su %.
     */
    private static function ganancia(Get $get, Producto $producto): Htmlable
    {
        $costoBase = $get('../../costo_base');
        $precio = $get('precio_base');

        if (! is_numeric($precio) || (float) $precio <= 0) {
            return new HtmlString('Sin precio');
        }

        if (! is_numeric($costoBase) || (float) $costoBase <= 0) {
            return new HtmlString('Sin costo todavía');
        }

        $contenido = is_numeric($get('contenido')) ? (int) $get('contenido') : 1;
        $costo = CalculadoraPrecio::conIsv(round((float) $costoBase * $contenido, 4), $producto->gravado);
        $ganancia = round(CalculadoraPrecio::conIsv(round((float) $precio, 4), $producto->gravado) - $costo, 2);

        $texto = $ganancia < 0
            ? '<span style="color: var(--danger-600); font-weight: 600">Pierde '.e(lempiras(abs($ganancia))).'</span>'
            : 'Gana '.e(lempiras($ganancia));

        if ($get('modo') === self::FIJO) {
            $sugerido = self::sugeridoEnPantalla($costoBase, $contenido, $get('margen_regla'), $producto);

            if ($sugerido !== null) {
                $texto .= '<br>Con '.e(self::numero($get('margen_regla'))).' %: '.e(lempiras($sugerido));
            }
        }

        return new HtmlString($texto);
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
            'utilidad del producto '.self::porcentaje($producto->margenAplicado()),
        ])->filter()->implode(' · ');
    }

    /**
     * Para la tarjeta de arriba: el costo promedio de hoy (y el corregido,
     * si se corrige), la última compra, la existencia y los avisos.
     *
     * @return array<string, mixed>
     */
    private static function resumen(Get $get, Producto $producto): array
    {
        $gravado = $producto->gravado;
        $base = $producto->presentacionBase()->sigla();
        $costoHoy = $producto->costo_unidad;
        $costoBase = is_numeric($get('costo_base')) ? (float) $get('costo_base') : $costoHoy;
        $corregido = (bool) $get('corregir_costo') && abs(round($costoBase, 4) - $costoHoy) >= 0.00005;

        $ultima = CompraItem::query()
            ->with(['compra', 'presentacion.unidadMedida'])
            ->where('producto_id', $producto->id)
            ->latest('id')
            ->first();

        $hay = (int) Existencia::query()->where('producto_id', $producto->id)->sum('cantidad');

        $avisos = [];

        foreach ((array) $get('presentaciones') as $fila) {
            if (! is_array($fila) || ! is_numeric($fila['precio_base'] ?? null) || (float) $fila['precio_base'] <= 0) {
                continue;
            }

            $presentacion = $producto->presentaciones->first(fn (Presentacion $p): bool => $p->id === (is_numeric($fila['id'] ?? null) ? (int) $fila['id'] : 0));

            if (! $presentacion instanceof Presentacion) {
                continue;
            }

            $precio = round((float) $fila['precio_base'], 4);
            $costo = round($costoBase * $presentacion->contenido, 4);
            $hoy = $presentacion->precio;

            if ($costoBase > 0 && $precio < $costo) {
                $avisos[] = "{$presentacion->sigla()} queda por debajo del costo: pierde ".lempiras(CalculadoraPrecio::conIsv($costo - $precio, $gravado)).' en cada una.';
            } elseif ($hoy > 0 && abs($precio - $hoy) / $hoy > self::CAMBIO_SOSPECHOSO) {
                $avisos[] = "{$presentacion->sigla()} cambia mucho contra hoy (".lempiras(CalculadoraPrecio::conIsv($hoy, $gravado)).' → '.lempiras(CalculadoraPrecio::conIsv($precio, $gravado)).'). Revise el monto.';
            }
        }

        return [
            'gravado'   => $gravado,
            'base'      => $base,
            'sinCosto'  => $costoHoy <= 0,
            'costo'     => lempiras(CalculadoraPrecio::conIsv($costoHoy, $gravado), 4),
            'costoNeto' => lempiras($costoHoy, 4),
            'corregido' => $corregido ? lempiras(CalculadoraPrecio::conIsv(round($costoBase, 4), $gravado), 4) : null,
            'ultima'    => $ultima instanceof CompraItem ? [
                'monto'   => lempiras($ultima->costo_ingresado),
                'sigla'   => $ultima->presentacion->sigla(),
                'porBase' => lempiras(CalculadoraPrecio::conIsv($ultima->costo_unidad, $gravado), 4),
                'fecha'   => $ultima->compra->fecha->format('d/m/Y'),
                'numero'  => (string) $ultima->compra->numero,
            ] : null,
            'existencia' => $producto->existenciaEnTexto($hay),
            'avisos'     => $avisos,
        ];
    }

    /**
     * 0.14 → "14%", 0.1525 → "15.25%".
     */
    private static function porcentaje(float $fraccion): string
    {
        return self::numero($fraccion * 100).'%';
    }

    /**
     * 14.0 → "14", 15.25 → "15.25".
     */
    private static function numero(mixed $valor): string
    {
        return rtrim(rtrim(number_format(is_numeric($valor) ? (float) $valor : 0.0, 2), '0'), '.');
    }
}
