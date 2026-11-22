<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlanesPromocion\Schemas;

use App\Domain\Promociones\GuardarPlanPromocion;
use App\Filament\Support\Acceso;
use App\Models\Bodega;
use App\Models\PlanPromocion;
use App\Models\Presentacion;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\PromocionCondicion;
use App\Models\Proveedor;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * El plan del proveedor como viene en su hoja:
 *
 *   Proveedor · Nombre · Desde · Hasta
 *   Bodegas (ninguna = todas) · La hoja del proveedor (imagen o PDF) · Notas
 *   Líneas autorizadas, una fila por línea de la hoja:
 *     Productos que compra | Compra | Regala | Producto que se regala | Tope (UN) | % hoja | Al costo
 *
 * "Compra + Regala" es la mecánica en UNIDADES: 24 + 1 se cumple con 24
 * unidades de cualquiera de los productos de la fila, mezclados, sueltas o
 * en caja. El tope son las unidades de regalo que autorizó el proveedor.
 * "Al costo" calcula el % que da la mecánica y la inversión autorizada (tope
 * × costo del regalo); si regala bastante más que el % de la hoja, se marca.
 *
 * Al guardar, cada fila queda como una promoción del plan
 * (GuardarPlanPromocion). Los productos que se buscan son del proveedor.
 */
final class PlanPromocionForm
{
    public static function configure(Schema $schema): Schema
    {
        $veTodo = Acceso::veTodo();

        return $schema
            ->columns(1)
            ->components([
                Section::make('Plan')
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->compact()
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 12])
                    ->schema([
                        Select::make('proveedor_id')
                            ->label('Proveedor')
                            ->options(fn (): array => self::proveedores())
                            ->searchable()
                            ->required()
                            // Los productos que se buscan abajo son de este proveedor.
                            ->live()
                            ->columnSpan(['xl' => 4]),
                        TextInput::make('nombre')
                            ->label('Nombre del plan')
                            ->placeholder('Ej. Plan OPL noviembre 2026')
                            ->default(fn (): string => 'Plan OPL '.today()->translatedFormat('F Y'))
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(['xl' => 4]),
                        DatePicker::make('vigente_desde')
                            ->label('Desde')
                            ->default(fn (): string => today()->startOfMonth()->toDateString())
                            ->required()
                            ->live()
                            ->columnSpan(['xl' => 2]),
                        DatePicker::make('vigente_hasta')
                            ->label('Hasta')
                            ->default(fn (): string => today()->endOfMonth()->toDateString())
                            ->minDate(fn (Get $get): ?string => filled($get('vigente_desde')) ? (string) $get('vigente_desde') : null)
                            ->required()
                            ->columnSpan(['xl' => 2]),
                        Select::make('bodegas')
                            ->label('Bodegas donde vale')
                            ->options(fn (): array => Bodega::query()->activas()->orderBy('nombre')->pluck('nombre', 'id')->all())
                            ->multiple()
                            ->placeholder('Todas las bodegas')
                            ->helperText('Sin escoger ninguna, vale en todas.')
                            ->visible($veTodo)
                            ->columnSpan(['xl' => 4]),
                        FileUpload::make('archivo')
                            ->label('Hoja del proveedor (imagen o PDF)')
                            ->disk(PlanPromocion::DISCO)
                            ->directory(PlanPromocion::CARPETA)
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'application/pdf'])
                            ->maxSize(10240)
                            ->openable()
                            ->downloadable()
                            ->columnSpan(['xl' => 4]),
                        TextInput::make('notas')
                            ->label('Notas (opcional)')
                            ->placeholder('Ej. Autorizado por el supervisor de zona')
                            ->maxLength(500)
                            ->columnSpan(['xl' => $veTodo ? 4 : 8]),
                    ]),

                Section::make('Líneas autorizadas')
                    ->description('Una fila por línea de la hoja. La mecánica cuenta unidades: 24 + 1 se cumple con 24 unidades de cualquiera de los productos de la fila (se pueden mezclar), sueltas o en caja. El tope son las unidades de regalo autorizadas: no se regala más.')
                    ->icon(Heroicon::OutlinedGift)
                    ->compact()
                    ->schema([
                        Repeater::make('lineas')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Productos que compra')->markAsRequired(),
                                TableColumn::make('Compra')->markAsRequired()->width('100px'),
                                TableColumn::make('Regala')->markAsRequired()->width('100px'),
                                TableColumn::make('Producto que se regala')->markAsRequired()->width('22%'),
                                TableColumn::make('Tope (UN)')->markAsRequired()->width('130px'),
                                TableColumn::make('% hoja')->width('110px'),
                                TableColumn::make('Al costo')->width('170px'),
                            ])
                            ->schema([
                                Hidden::make('id'),
                                Select::make('productos')
                                    ->hiddenLabel()
                                    ->multiple()
                                    ->searchable()
                                    ->placeholder('Buscar por código o nombre…')
                                    ->getSearchResultsUsing(fn (string $search, Get $get): array => self::buscar($search, $get('../../proveedor_id')))
                                    ->getOptionLabelsUsing(fn (array $values): array => self::etiquetas($values))
                                    ->required()
                                    ->live()
                                    // El regalo suele ser el mismo: si no hay, el primero.
                                    ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                                        if (blank($get('regalo_id')) && is_array($state) && $state !== []) {
                                            $set('regalo_id', (int) reset($state));
                                        }
                                    }),
                                TextInput::make('compra')
                                    ->hiddenLabel()
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->placeholder('24')
                                    ->required()
                                    ->live(debounce: 500),
                                TextInput::make('regala')
                                    ->hiddenLabel()
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required()
                                    ->live(debounce: 500),
                                Select::make('regalo_id')
                                    ->hiddenLabel()
                                    ->searchable()
                                    ->placeholder('Buscar…')
                                    ->getSearchResultsUsing(fn (string $search, Get $get): array => self::buscar($search, $get('../../proveedor_id')))
                                    ->getOptionLabelUsing(fn (mixed $value): ?string => self::etiquetas([$value])[(int) $value] ?? null)
                                    ->required()
                                    ->live(),
                                TextInput::make('tope')
                                    ->hiddenLabel()
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->placeholder('500')
                                    ->required()
                                    ->live(debounce: 500),
                                TextInput::make('porcentaje')
                                    ->hiddenLabel()
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->placeholder('4.17')
                                    ->live(debounce: 500),
                                TextEntry::make('al_costo')
                                    ->hiddenLabel()
                                    ->state(fn (Get $get): string => self::alCosto($get))
                                    ->color(fn (Get $get): string => self::excede($get) ? 'danger' : 'gray')
                                    ->weight(fn (Get $get): ?FontWeight => self::excede($get) ? FontWeight::Bold : null)
                                    ->size(TextSize::Small),
                            ])
                            ->defaultItems(1)
                            ->minItems(1)
                            ->addActionLabel('Agregar otra línea')
                            ->reorderable(false),
                    ]),
            ]);
    }

    /**
     * Los datos del plan para el formulario de cambiar: sus bodegas y una
     * fila por cada línea (promoción).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function llenar(PlanPromocion $plan, array $data): array
    {
        $plan->loadMissing(['bodegas', 'promociones.condiciones']);

        $data['bodegas'] = $plan->bodegas->modelKeys();
        $data['lineas'] = $plan->promociones->map(fn (Promocion $p): array => [
            'id'         => $p->id,
            'productos'  => $p->condiciones->map(fn (PromocionCondicion $c): int => $c->producto_id)->values()->all(),
            'compra'     => $p->compra_unidades,
            'regala'     => $p->cantidad_beneficio,
            'regalo_id'  => $p->producto_beneficio_id,
            'tope'       => $p->tope_unidades,
            'porcentaje' => $p->porcentaje_autorizado,
        ])->values()->all();

        return $data;
    }

    /**
     * Lo del formulario como lo pide GuardarPlanPromocion.
     *
     * @param array<string, mixed> $data
     *
     * @return array{proveedor_id: int, nombre: string, vigente_desde: string, vigente_hasta: string, archivo: string|null, notas: string|null, bodegas: list<int>, lineas: list<array{id: int|null, productos: list<int>, compra: int, regala: int, regalo_id: int, tope: int, porcentaje: float|null}>}
     */
    public static function datos(array $data): array
    {
        $lineas = [];

        foreach (is_array($data['lineas'] ?? null) ? $data['lineas'] : [] as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $lineas[] = [
                'id'         => is_numeric($fila['id'] ?? null) ? (int) $fila['id'] : null,
                'productos'  => self::enteros($fila['productos'] ?? []),
                'compra'     => self::entero($fila['compra'] ?? null),
                'regala'     => self::entero($fila['regala'] ?? null),
                'regalo_id'  => self::entero($fila['regalo_id'] ?? null),
                'tope'       => self::entero($fila['tope'] ?? null),
                'porcentaje' => is_numeric($fila['porcentaje'] ?? null) ? round((float) $fila['porcentaje'], 2) : null,
            ];
        }

        $archivo = $data['archivo'] ?? null;

        if (is_array($archivo)) {
            $archivo = reset($archivo);
        }

        return [
            'proveedor_id'  => self::entero($data['proveedor_id'] ?? null),
            'nombre'        => is_string($data['nombre'] ?? null) ? $data['nombre'] : '',
            'vigente_desde' => Carbon::parse(is_string($data['vigente_desde'] ?? null) ? $data['vigente_desde'] : 'today')->toDateString(),
            'vigente_hasta' => Carbon::parse(is_string($data['vigente_hasta'] ?? null) ? $data['vigente_hasta'] : 'today')->toDateString(),
            'archivo'       => is_string($archivo) && $archivo !== '' ? $archivo : null,
            'notas'         => is_string($data['notas'] ?? null) && $data['notas'] !== '' ? $data['notas'] : null,
            'bodegas'       => self::enteros($data['bodegas'] ?? []),
            'lineas'        => $lineas,
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function proveedores(): array
    {
        /** @var array<int, string> $opciones */
        $opciones = Proveedor::query()->activos()->orderBy('nombre')->pluck('nombre', 'id')->all();

        return $opciones;
    }

    /**
     * Productos activos del proveedor (si ya se escogió) que coinciden.
     *
     * @return array<int, string>
     */
    private static function buscar(string $texto, mixed $proveedorId): array
    {
        /** @var array<int, string> $opciones */
        $opciones = Producto::query()
            ->activos()
            ->buscar($texto)
            ->when(is_numeric($proveedorId), fn (Builder $query): Builder => $query->where('proveedor_id', (int) $proveedorId))
            ->orderBy('descripcion')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Producto $producto): array => [$producto->id => $producto->etiqueta()])
            ->all();

        return $opciones;
    }

    /**
     * @param array<mixed> $ids
     *
     * @return array<int, string>
     */
    private static function etiquetas(array $ids): array
    {
        /** @var array<int, string> $etiquetas */
        $etiquetas = Producto::query()
            ->whereKey(self::enteros($ids))
            ->get()
            ->mapWithKeys(fn (Producto $producto): array => [$producto->id => $producto->etiqueta()])
            ->all();

        return $etiquetas;
    }

    /**
     * "4.17 % · L 5,875.00": el % que da la mecánica al costo y la inversión
     * autorizada (tope × costo del regalo).
     */
    private static function alCosto(Get $get): string
    {
        [$porcentaje, $inversion] = self::calculo($get);

        if ($porcentaje === null && $inversion === null) {
            return '—';
        }

        return ($porcentaje !== null ? Promocion::porcentajeTexto($porcentaje) : '—').' · '.($inversion !== null ? lempiras($inversion) : '—');
    }

    /**
     * Regala bastante más (al costo) que el % de la hoja.
     */
    private static function excede(Get $get): bool
    {
        [$porcentaje] = self::calculo($get);
        $autorizado = $get('porcentaje');

        return $porcentaje !== null && is_numeric($autorizado) && $porcentaje > (float) $autorizado + 0.25;
    }

    /**
     * @return array{0: float|null, 1: float|null} [% al costo, inversión autorizada]
     */
    private static function calculo(Get $get): array
    {
        $productos = self::enteros($get('productos') ?? []);
        $regaloId = $get('regalo_id');
        $bases = Presentacion::query()
            ->whereIn('producto_id', array_filter([...$productos, is_numeric($regaloId) ? (int) $regaloId : 0]))
            ->where('es_base', true)
            ->get()
            ->keyBy('producto_id');

        $baseCompra = $productos !== [] ? $bases->get($productos[0]) : null;
        $baseRegalo = is_numeric($regaloId) ? $bases->get((int) $regaloId) : null;
        $porcentaje = GuardarPlanPromocion::porcentaje(self::entero($get('compra')), self::entero($get('regala')), $baseCompra, $baseRegalo);
        $tope = $get('tope');
        $inversion = $baseRegalo instanceof Presentacion && is_numeric($tope) ? round((int) $tope * $baseRegalo->costo, 2) : null;

        return [$porcentaje, $inversion];
    }

    private static function entero(mixed $valor): int
    {
        return is_numeric($valor) ? (int) $valor : 0;
    }

    /**
     * @return list<int>
     */
    private static function enteros(mixed $valores): array
    {
        $ids = [];

        foreach (is_array($valores) ? $valores : [] as $valor) {
            if (is_numeric($valor) && (int) $valor > 0) {
                $ids[] = (int) $valor;
            }
        }

        return array_values(array_unique($ids));
    }
}
