<?php

declare(strict_types=1);

namespace App\Filament\Resources\Promociones\Schemas;

use App\Enums\UnidadVenta;
use App\Filament\Support\Acceso;
use App\Models\Bodega;
use App\Models\Existencia;
use App\Models\Producto;
use App\Models\Promocion;
use App\Support\Texto;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Todo a la vista, sin scroll:
 *
 *   Nombre ··················· Desde · Hasta · Activa
 *   [Compra: uno o varios productos, ancho   [Así la ve el vendedor]
 *    para que se lean los nombres largos]     (se arma mientras se escribe)
 *   [Se lleva: producto · cantidad · cómo]   Nota para el vendedor
 *
 * La tarjeta es resources/views/filament/promociones/vista-previa.blade.php.
 * El código (PROMO-0001) se genera solo al guardar.
 *
 * Bodegas: administración escoge una o varias (ninguna = todas); la del
 * encargado queda en su bodega. En el buscador de productos sale cuánto hay
 * de cada uno en esas bodegas, primero los que tienen.
 */
class PromocionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(12)
            ->components([
                TextInput::make('nombre')
                    ->label('Nombre de la promoción')
                    ->placeholder('Ej. Toffee + Nutrine')
                    ->required()
                    ->maxLength(255)
                    ->live(debounce: 500)
                    ->columnSpan(6),
                DatePicker::make('vigente_desde')
                    ->label('Desde')
                    ->live()
                    ->columnSpan(2),
                DatePicker::make('vigente_hasta')
                    ->label('Hasta')
                    ->minDate(fn (Get $get): ?string => filled($get('vigente_desde')) ? (string) $get('vigente_desde') : null)
                    ->live()
                    ->columnSpan(2),
                Toggle::make('activa')
                    ->label('Activa')
                    ->default(true)
                    ->onColor('success')
                    ->inline(false)
                    ->live()
                    ->columnSpan(2),

                Group::make([
                    Section::make('El cliente compra')
                        ->icon(Heroicon::OutlinedShoppingCart)
                        ->compact()
                        ->schema([
                            Repeater::make('condiciones')
                                ->hiddenLabel()
                                ->relationship()
                                ->table([
                                    TableColumn::make('Producto')->markAsRequired(),
                                    TableColumn::make('Cantidad')->markAsRequired()->width('90px'),
                                    TableColumn::make('En')->width('115px'),
                                ])
                                ->schema([
                                    self::producto('producto_id', '../../bodegas')
                                        ->hiddenLabel()
                                        ->distinct(),
                                    self::cantidad('cantidad')
                                        ->hiddenLabel(),
                                    self::unidad('unidad')
                                        ->hiddenLabel(),
                                ])
                                ->minItems(1)
                                ->defaultItems(1)
                                ->addActionLabel('Agregar otro producto')
                                ->reorderable(false),
                        ]),

                    Section::make('Se lleva')
                        ->icon(Heroicon::OutlinedGift)
                        ->compact()
                        ->columns(4)
                        ->schema([
                            self::producto('producto_beneficio_id', 'bodegas')
                                ->label('Producto')
                                ->columnSpanFull(),
                            self::cantidad('cantidad_beneficio')
                                ->label('Cantidad'),
                            self::unidad('beneficio_unidad')
                                ->label('En'),
                            ToggleButtons::make('tipo')
                                ->label('¿Cómo sale?')
                                ->options([
                                    'gratis' => 'Gratis',
                                    'mitad'  => 'Mitad de precio',
                                    'otro'   => 'Otro %',
                                ])
                                ->icons([
                                    'gratis' => Heroicon::OutlinedGift,
                                    'mitad'  => Heroicon::OutlinedReceiptPercent,
                                    'otro'   => Heroicon::OutlinedAdjustmentsHorizontal,
                                ])
                                ->grouped()
                                ->default('gratis')
                                ->required()
                                ->live()
                                ->afterStateHydrated(function (ToggleButtons $component, ?Promocion $record): void {
                                    if ($record instanceof Promocion) {
                                        $component->state($record->tipo());
                                    }
                                })
                                ->columnSpan(2),
                            TextInput::make('descuento_porcentaje')
                                ->label('Descuento')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(100)
                                ->suffix('%')
                                ->required(fn (Get $get): bool => $get('tipo') === 'otro')
                                ->visible(fn (Get $get): bool => $get('tipo') === 'otro')
                                ->live(debounce: 500),
                        ]),
                ])->columnSpan(8),

                Group::make([
                    Select::make('bodegas')
                        ->label('Bodegas donde vale')
                        ->relationship('bodegas', 'nombre', fn (Builder $query): Builder => $query->where('activa', true)->orderBy('nombre'))
                        ->multiple()
                        ->preload()
                        ->placeholder('Todas las bodegas')
                        ->helperText('Sin escoger ninguna, vale en todas.')
                        ->live()
                        ->visible(fn (): bool => Acceso::veTodo()),
                    View::make('filament.promociones.vista-previa')
                        ->viewData(fn (Get $get, ?Promocion $record): array => self::vistaPrevia($get, $record)),
                    TextInput::make('descripcion')
                        ->label('Nota para el vendedor (opcional)')
                        ->placeholder('Ej. Válida solo para pulperías')
                        ->maxLength(500)
                        ->live(debounce: 500),
                ])->columnSpan(4),
            ]);
    }

    /**
     * Pasa "gratis / mitad / otro" al porcentaje que se guarda.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function completar(array $data): array
    {
        $tipo = $data['tipo'] ?? 'gratis';
        unset($data['tipo']);

        $data['descuento_porcentaje'] = match ($tipo) {
            'gratis' => 100.0,
            'mitad'  => 50.0,
            default  => is_numeric($data['descuento_porcentaje'] ?? null) ? (float) $data['descuento_porcentaje'] : 100.0,
        };

        return $data;
    }

    /**
     * La promoción del encargado queda en su bodega (él no la escoge).
     */
    public static function enSuBodega(Promocion $promocion): void
    {
        $usuario = Acceso::usuario();

        if ($usuario === null || $usuario->veTodasLasBodegas() || $usuario->bodega_id === null) {
            return;
        }

        $promocion->bodegas()->sync([$usuario->bodega_id]);
    }

    /**
     * Buscador de productos con cuánto hay de cada uno en las bodegas de la
     * promoción ($rutaBodegas: dónde está el campo "bodegas" desde este).
     */
    private static function producto(string $campo, string $rutaBodegas): Select
    {
        return Select::make($campo)
            ->placeholder('Buscar por código o nombre…')
            ->searchable()
            ->allowHtml()
            ->getSearchResultsUsing(fn (string $search, Get $get): array => self::opciones($search, self::bodegasDeExistencia($get($rutaBodegas))))
            ->getOptionLabelUsing(function (mixed $value, Get $get): ?string {
                $producto = Producto::query()->whereKey($value)->first();

                if (! $producto instanceof Producto) {
                    return null;
                }

                $hay = self::existenciaEn(self::bodegasDeExistencia($get($rutaBodegas)), [$producto->id]);

                return self::etiquetaConExistencia($producto, $hay[$producto->id] ?? 0);
            })
            ->required()
            ->live();
    }

    /**
     * Productos activos que coinciden, los que tienen existencia primero.
     *
     * @param list<int>|null $bodegas
     *
     * @return array<int, string>
     */
    private static function opciones(string $buscar, ?array $bodegas): array
    {
        $productos = Producto::query()
            ->activos()
            ->buscar($buscar)
            ->orderBy('descripcion')
            ->limit(50)
            ->get();

        $hay = self::existenciaEn($bodegas, $productos->modelKeys());

        /** @var array<int, string> $opciones */
        $opciones = $productos
            ->sortBy(fn (Producto $producto): int => ($hay[$producto->id] ?? 0) > 0 ? 0 : 1)
            ->mapWithKeys(fn (Producto $producto): array => [$producto->id => self::etiquetaConExistencia($producto, $hay[$producto->id] ?? 0)])
            ->all();

        return $opciones;
    }

    /**
     * "CÓDIGO · DESCRIPCIÓN" y, a la derecha, "Hay 3 CJ + 2 UN" (verde) o
     * "Sin existencia" (rojo).
     */
    private static function etiquetaConExistencia(Producto $producto, int $hay): string
    {
        $texto = $hay > 0 ? 'Hay '.Existencia::enCajas($hay, $producto->unidades_por_caja) : 'Sin existencia';
        $color = $hay > 0 ? 'var(--success-500)' : 'var(--danger-500)';

        return '<span style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;width:100%">'
            .'<span style="min-width:0">'.e($producto->etiqueta()).'</span>'
            .'<span style="flex-shrink:0;font-size:.75rem;font-weight:700;color:'.$color.'">'.e($texto).'</span>'
            .'</span>';
    }

    /**
     * En qué bodegas se cuenta la existencia: la del encargado; para
     * administración, las que escogió (null = todas).
     *
     * @return list<int>|null
     */
    private static function bodegasDeExistencia(mixed $escogidas): ?array
    {
        $usuario = Acceso::usuario();

        if ($usuario !== null && ! $usuario->veTodasLasBodegas()) {
            return $usuario->bodega_id !== null ? [$usuario->bodega_id] : [];
        }

        $ids = [];

        foreach (is_array($escogidas) ? $escogidas : [] as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids === [] ? null : $ids;
    }

    /**
     * [producto_id => unidades] en esas bodegas (null = todas).
     *
     * @param list<int>|null $bodegas
     * @param array<mixed> $productos
     *
     * @return array<int, int>
     */
    private static function existenciaEn(?array $bodegas, array $productos): array
    {
        if ($productos === [] || $bodegas === []) {
            return [];
        }

        /** @var array<int, int> $hay */
        $hay = Existencia::query()
            ->whereIn('producto_id', $productos)
            ->when($bodegas !== null, fn (Builder $query): Builder => $query->whereIn('bodega_id', $bodegas ?? []))
            ->groupBy('producto_id')
            ->selectRaw('producto_id, SUM(cantidad) as total')
            ->pluck('total', 'producto_id')
            ->map(fn (mixed $cantidad): int => (int) $cantidad)
            ->all();

        return $hay;
    }

    /**
     * "Todas las bodegas", o las que escogió administración, o la del encargado.
     */
    private static function textoBodegas(mixed $escogidas): string
    {
        $ids = self::bodegasDeExistencia($escogidas);

        if ($ids === null) {
            return 'Todas las bodegas';
        }

        $nombres = Bodega::query()->whereKey($ids)->orderBy('nombre')->pluck('nombre')->all();

        return $nombres === [] ? 'Todas las bodegas' : implode(', ', $nombres);
    }

    private static function cantidad(string $campo): TextInput
    {
        return TextInput::make($campo)
            ->numeric()
            ->integer()
            ->minValue(1)
            ->default(1)
            ->required()
            ->live(debounce: 500);
    }

    private static function unidad(string $campo): Select
    {
        return Select::make($campo)
            ->options(UnidadVenta::class)
            ->default(UnidadVenta::Unidad->value)
            ->selectablePlaceholder(false)
            ->required()
            ->live();
    }

    /**
     * Datos para la tarjeta "Así la ve el vendedor", armados con lo que va escrito.
     *
     * @return array<string, mixed>
     */
    private static function vistaPrevia(Get $get, ?Promocion $record): array
    {
        $filas = $get('condiciones');
        $filas = is_array($filas) ? $filas : [];
        $ids = collect($filas)->pluck('producto_id')->push($get('producto_beneficio_id'))->filter()->unique()->all();
        $productos = Producto::query()->whereKey($ids)->get()->keyBy('id');

        $compra = [];
        $idsCompra = [];

        foreach ($filas as $fila) {
            $producto = is_array($fila) ? $productos->get($fila['producto_id'] ?? null) : null;

            if (! is_array($fila) || ! $producto instanceof Producto) {
                continue;
            }

            $idsCompra[] = $producto->id;
            $compra[] = [
                'cantidad' => Promocion::cantidadTexto(max(1, (int) ($fila['cantidad'] ?? 1)), self::unidadDe($fila['unidad'] ?? null)),
                'producto' => $producto->descripcion,
            ];
        }

        $regalo = $productos->get($get('producto_beneficio_id'));
        $datos = self::completar(['tipo' => $get('tipo'), 'descuento_porcentaje' => $get('descuento_porcentaje')]);
        $porcentaje = is_numeric($datos['descuento_porcentaje'] ?? null) ? (float) $datos['descuento_porcentaje'] : 100.0;

        return [
            'nombre'   => Texto::mayusculas((string) $get('nombre')),
            'codigo'   => $record->codigo ?? null,
            'bodegas'  => self::textoBodegas($get('bodegas')),
            'activa'   => (bool) $get('activa'),
            'vigencia' => self::vigencia($get('vigente_desde'), $get('vigente_hasta')),
            'compra'   => $compra,
            'regalo'   => $regalo instanceof Producto ? [
                'cantidad' => Promocion::cantidadTexto(max(1, (int) $get('cantidad_beneficio')), self::unidadDe($get('beneficio_unidad'))),
                'producto' => $regalo->descripcion,
                'mismo'    => in_array($regalo->id, $idsCompra, true),
            ] : null,
            'beneficio' => match (Promocion::tipoPara($porcentaje)) {
                'gratis' => 'Gratis',
                'mitad'  => 'A mitad de precio',
                default  => Promocion::porcentajeTexto($porcentaje).' de descuento',
            },
            'nota' => Texto::mayusculas((string) $get('descripcion')),
        ];
    }

    private static function vigencia(mixed $desde, mixed $hasta): string
    {
        $desde = self::fecha($desde);
        $hasta = self::fecha($hasta);

        return match (true) {
            $desde !== null && $hasta !== null => "Del {$desde} al {$hasta}",
            $desde !== null                    => "Desde el {$desde}",
            $hasta !== null                    => "Hasta el {$hasta}",
            default                            => 'Sin fecha límite',
        };
    }

    private static function fecha(mixed $valor): ?string
    {
        if (! is_string($valor) || $valor === '') {
            return null;
        }

        try {
            return Carbon::parse($valor)->format('d/m/Y');
        } catch (Throwable) {
            return null;
        }
    }

    private static function unidadDe(mixed $valor): UnidadVenta
    {
        if ($valor instanceof UnidadVenta) {
            return $valor;
        }

        return UnidadVenta::tryFrom(is_string($valor) ? $valor : '') ?? UnidadVenta::Unidad;
    }
}
