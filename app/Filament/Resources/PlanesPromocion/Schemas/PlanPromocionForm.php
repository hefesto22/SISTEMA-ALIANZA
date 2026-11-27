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
use Illuminate\Support\Carbon;

/**
 * El plan del proveedor como viene en su hoja:
 *
 *   Proveedor · Nombre · Desde · Hasta
 *   Bodegas (ninguna = todas) · La hoja del proveedor (imagen o PDF) · Notas
 *   Líneas autorizadas, una tarjeta por línea de la hoja (se encogen y se copian):
 *     Productos que compra (del proveedor escogido arriba)
 *     Compra · Regala · Tope autorizado · % de la hoja
 *     Al costo: el % que da la mecánica y la inversión, o lo que falta para calcularlos
 *
 * "Compra + Regala" es la mecánica en UNIDADES de cada producto aparte (no
 * se mezclan): 24 + 1 se cumple con 24 unidades de un mismo producto,
 * sueltas o en caja, y se regala 1 unidad de ese mismo. El tope son las
 * unidades de regalo que autorizó el proveedor para toda la línea. "Al
 * costo" calcula el % que da la mecánica y la inversión autorizada (tope ×
 * costo de la unidad, en promedio si los productos cuestan distinto); si
 * regala bastante más que el % de la hoja, se marca.
 *
 * Al guardar, cada fila queda como una promoción del plan
 * (GuardarPlanPromocion). Los productos de las líneas son solo los del
 * proveedor escogido; si se cambia de proveedor, se quitan los de otro.
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
                            // Los productos de las líneas son de este proveedor: si cambia, se quitan los de otro.
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                                $suyos = self::productosDe($state);
                                $lineas = is_array($get('lineas')) ? $get('lineas') : [];

                                foreach ($lineas as $clave => $linea) {
                                    if (! is_array($linea)) {
                                        continue;
                                    }

                                    $linea['productos'] = array_values(array_filter(self::enteros($linea['productos'] ?? []), fn (int $id): bool => isset($suyos[$id])));
                                    $lineas[$clave] = $linea;
                                }

                                $set('lineas', $lineas);
                            })
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
                    ->description('Una por cada línea de la hoja. Cada producto cuenta aparte, no se mezclan: 24 + 1 se cumple con 24 unidades de un mismo producto, sueltas o en caja, y se regala de ese mismo producto. El tope son las unidades de regalo autorizadas para toda la línea: no se regala más.')
                    ->icon(Heroicon::OutlinedGift)
                    ->compact()
                    ->schema([
                        Repeater::make('lineas')
                            ->hiddenLabel()
                            ->columns(['default' => 2, 'lg' => 12])
                            ->itemLabel(fn (array $state): string => self::tituloLinea($state))
                            ->schema([
                                Hidden::make('id'),
                                Select::make('productos')
                                    ->label('Productos que compra el cliente (cada uno aparte; se regala del mismo)')
                                    ->multiple()
                                    ->searchable()
                                    // Solo los del proveedor escogido arriba.
                                    ->options(fn (Get $get): array => self::productosDe($get('../../proveedor_id')))
                                    ->placeholder(fn (Get $get): string => filled($get('../../proveedor_id')) ? 'Escoja uno o varios productos…' : 'Primero escoja el proveedor arriba')
                                    ->disabled(fn (Get $get): bool => blank($get('../../proveedor_id')))
                                    ->required()
                                    ->live()
                                    ->columnSpan(['default' => 2, 'lg' => 12]),
                                TextInput::make('compra')
                                    ->label('Compra (unidades)')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->placeholder('Ej. 24')
                                    ->required()
                                    ->live(debounce: 500)
                                    ->columnSpan(['default' => 1, 'lg' => 3]),
                                TextInput::make('regala')
                                    ->label('Regala (unidades)')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required()
                                    ->live(debounce: 500)
                                    ->columnSpan(['default' => 1, 'lg' => 3]),
                                TextInput::make('tope')
                                    ->label('Tope autorizado (UN)')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->placeholder('Ej. 500')
                                    ->required()
                                    ->live(debounce: 500)
                                    ->columnSpan(['default' => 1, 'lg' => 3]),
                                TextInput::make('porcentaje')
                                    ->label('% de la hoja')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->placeholder('Ej. 4.17')
                                    ->live(debounce: 500)
                                    ->columnSpan(['default' => 1, 'lg' => 3]),
                                TextEntry::make('al_costo')
                                    ->label('Al costo')
                                    ->state(fn (Get $get): string => self::alCosto($get))
                                    ->color(fn (Get $get): string => self::excede($get) ? 'danger' : 'gray')
                                    ->weight(fn (Get $get): ?FontWeight => self::excede($get) ? FontWeight::Bold : null)
                                    ->size(TextSize::Small)
                                    ->columnSpan(['default' => 2, 'lg' => 12]),
                            ])
                            ->defaultItems(1)
                            ->minItems(1)
                            ->collapsible()
                            ->cloneable()
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
     * @return array{proveedor_id: int, nombre: string, vigente_desde: string, vigente_hasta: string, archivo: string|null, notas: string|null, bodegas: list<int>, lineas: list<array{id: int|null, productos: list<int>, compra: int, regala: int, tope: int, porcentaje: float|null}>}
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
     * Los productos activos del proveedor, para escoger en las líneas (una
     * consulta por proveedor en cada petición). Sin proveedor, ninguno.
     *
     * @return array<int, string>
     */
    private static function productosDe(mixed $proveedorId): array
    {
        if (! is_numeric($proveedorId)) {
            return [];
        }

        /** @var array<int, array<int, string>> $cache */
        static $cache = [];

        if (! isset($cache[(int) $proveedorId])) {
            /** @var array<int, string> $opciones */
            $opciones = Producto::query()
                ->activos()
                ->where('proveedor_id', (int) $proveedorId)
                ->orderBy('descripcion')
                ->get()
                ->mapWithKeys(fn (Producto $producto): array => [$producto->id => $producto->etiqueta()])
                ->all();

            $cache[(int) $proveedorId] = $opciones;
        }

        return $cache[(int) $proveedorId];
    }

    /**
     * "Línea 24+1 · 3 productos · tope 500" (lo que lleva la línea, para cuando se encoge).
     *
     * @param array<string, mixed> $state
     */
    private static function tituloLinea(array $state): string
    {
        $compra = is_numeric($state['compra'] ?? null) ? (int) $state['compra'] : null;
        $regala = is_numeric($state['regala'] ?? null) ? (int) $state['regala'] : null;
        $productos = count(self::enteros($state['productos'] ?? []));
        $partes = [$compra !== null && $regala !== null ? "Línea {$compra}+{$regala}" : 'Línea nueva'];

        if ($productos > 0) {
            $partes[] = $productos === 1 ? '1 producto' : "{$productos} productos";
        }

        if (is_numeric($state['tope'] ?? null)) {
            $partes[] = 'tope '.number_format((int) $state['tope']);
        }

        return implode(' · ', $partes);
    }

    /**
     * "Regala 4.17 % al costo (la hoja dice 4.17 %) · Inversión autorizada
     * L 3,435.00 (500 × L 6.87 la unidad)". Si falta algo, lo dice.
     */
    private static function alCosto(Get $get): string
    {
        $faltan = [];

        if (self::enteros($get('productos') ?? []) === []) {
            $faltan[] = 'los productos';
        }

        if (self::entero($get('compra')) < 1) {
            $faltan[] = 'cuántas compra';
        }

        if (! is_numeric($get('tope'))) {
            $faltan[] = 'el tope';
        }

        if ($faltan !== []) {
            return 'Para calcular el % y la inversión falta: '.implode(', ', $faltan).'.';
        }

        [$porcentaje, $inversion, $costo] = self::calculo($get);
        $autorizado = $get('porcentaje');

        $texto = $porcentaje !== null
            ? 'Regala '.Promocion::porcentajeTexto($porcentaje).' al costo'.(is_numeric($autorizado) ? ' (la hoja dice '.Promocion::porcentajeTexto((float) $autorizado).')' : '')
            : 'Sin costo registrado para calcular el %';

        if ($inversion !== null && $costo !== null) {
            $texto .= ' · Inversión autorizada '.lempiras($inversion).' ('.number_format(self::entero($get('tope'))).' × '.lempiras($costo).' la unidad'.(count(self::enteros($get('productos') ?? [])) > 1 ? ', en promedio' : '').')';
        }

        return self::excede($get) ? $texto.' · Regala más que la hoja: revise la mecánica.' : $texto;
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
     * Cada producto regala del mismo: el % sale del primero y la inversión,
     * del costo promedio de la unidad de los de la línea.
     *
     * @return array{0: float|null, 1: float|null, 2: float|null} [% al costo, inversión autorizada, costo de la unidad del regalo]
     */
    private static function calculo(Get $get): array
    {
        $productos = self::enteros($get('productos') ?? []);
        $bases = Presentacion::query()
            ->whereIn('producto_id', $productos)
            ->where('es_base', true)
            ->get()
            ->keyBy('producto_id');

        $primera = $productos !== [] ? $bases->get($productos[0]) : null;
        $porcentaje = GuardarPlanPromocion::porcentaje(self::entero($get('compra')), self::entero($get('regala')), $primera, $primera);
        $costo = GuardarPlanPromocion::costoDelRegalo($bases->all());
        $tope = $get('tope');
        $inversion = $costo !== null && is_numeric($tope) ? round((int) $tope * $costo, 2) : null;

        return [$porcentaje, $inversion, $costo !== null ? round($costo, 2) : null];
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
