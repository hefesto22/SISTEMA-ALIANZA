<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Schemas;

use App\Models\Categoria;
use App\Models\Presentacion;
use App\Models\Producto;
use App\Models\UnidadMedida;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

/**
 * Datos del producto y sus presentaciones.
 *
 * Presentaciones: la base (en qué se cuenta la existencia, casi siempre UN;
 * trae 1) siempre está y se dice si se vende por ella. Lo que no trae
 * unidades (un saco, un galón) se cuenta en SC o GL: esa es su base. Abajo,
 * en una tabla, las que traen varias del MISMO producto (mismo código): CJ
 * de 8, CJ de 10, FD de 6… Cada una se vende con su precio.
 *
 * Al crear, costo y precios son opcionales: el costo lo pone la primera
 * compra y el precio sale del costo con el % de utilidad; si se escribe un
 * precio, queda ese. Después se cambian con "Actualizar precio" para que
 * quede historial.
 *
 * Los campos de presentaciones no son columnas del producto: los guarda
 * CreateProducto / EditProducto con App\Domain\Productos\GuardarPresentaciones.
 */
class ProductoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Producto')
                    ->compact()
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                    ->columnSpanFull()
                    ->schema([
                        Select::make('proveedor_id')
                            ->label('Proveedor')
                            ->relationship('proveedor', 'nombre')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('categoria_id', null))
                            ->columnSpan(['xl' => 2]),
                        Select::make('categoria_id')
                            ->label('Categoría (% de utilidad)')
                            ->options(fn (Get $get): array => Categoria::query()
                                ->where('proveedor_id', $get('proveedor_id'))
                                ->orderBy('nombre')
                                ->get()
                                ->mapWithKeys(fn (Categoria $c): array => [
                                    $c->id => $c->nombre.' ('.rtrim(rtrim(number_format($c->margen * 100, 2), '0'), '.').'%)',
                                ])
                                ->all())
                            ->required()
                            ->disabled(fn (Get $get): bool => blank($get('proveedor_id')))
                            ->columnSpan(['xl' => 2]),
                        TextInput::make('codigo')
                            ->label('Código')
                            ->required()
                            ->maxLength(50)
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('proveedor_id', $get('proveedor_id')),
                            ),
                        TextInput::make('descripcion')
                            ->label('Descripción')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(['md' => 2, 'xl' => 3])
                            ->dehydrateStateUsing(fn (?string $state): string => mb_strtoupper(trim((string) $state))),
                        Select::make('unidad_medida_id')
                            ->label('Se cuenta en (base)')
                            ->options(fn (?Producto $record): array => UnidadMedida::opciones($record?->unidad_medida_id))
                            ->default(fn (): ?int => UnidadMedida::query()->where('codigo', UnidadMedida::BASE)->value('id'))
                            ->helperText('Existencia y costo van en esta unidad.')
                            ->required()
                            ->live(),
                        TextInput::make('margen')
                            ->label('% de utilidad propio')
                            ->placeholder('El de la categoría')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(99.99)
                            ->step(0.01)
                            ->suffix('%')
                            ->formatStateUsing(fn (mixed $state): ?float => is_numeric($state) ? round((float) $state * 100, 2) : null)
                            ->dehydrateStateUsing(fn (mixed $state): ?float => is_numeric($state) ? round((float) $state / 100, 4) : null),
                        Toggle::make('gravado')
                            ->label('Paga ISV')
                            ->helperText('Apagado = exento.')
                            ->inline(false)
                            ->default(true)
                            ->live(),
                        Toggle::make('activo')
                            ->label('Activo (se vende)')
                            ->inline(false)
                            ->default(true),
                    ]),

                Section::make('Presentaciones')
                    ->description(fn (string $operation): string => $operation === 'create'
                        ? 'La base siempre está. Agregue las que traen varias (CJ de 12, FD de 6…). Costo y precio son opcionales: sin precio, sale del costo con el % de utilidad.'
                        : 'Lo que trae una que ya se usó no se cambia: apáguela y agregue otra. Los precios se cambian con "Actualizar precio".')
                    ->compact()
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 4])
                            ->schema([
                                Toggle::make('base_activa')
                                    ->label(fn (Get $get): string => 'Se vende por '.self::codigo($get('unidad_medida_id')).' (la base)')
                                    ->helperText('Apagado: solo en las de abajo.')
                                    ->default(true)
                                    ->dehydrated(false)
                                    ->columnSpan(['md' => 2]),
                                TextInput::make('base_costo')
                                    ->label(fn (Get $get): string => 'Costo '.self::codigo($get('unidad_medida_id')).($get('gravado') ? ' con ISV' : ''))
                                    ->numeric()
                                    ->minValue(0)
                                    ->prefix('L.')
                                    ->placeholder('Opcional')
                                    ->dehydrated(false)
                                    ->visibleOn('create'),
                                TextInput::make('base_precio')
                                    ->label(fn (Get $get): string => 'Precio '.self::codigo($get('unidad_medida_id')).($get('gravado') ? ' con ISV' : ''))
                                    ->numeric()
                                    ->minValue(0.01)
                                    ->prefix('L.')
                                    ->placeholder('Opcional')
                                    ->dehydrated(false)
                                    ->visibleOn('create'),
                            ]),
                        Repeater::make('otras')
                            ->label('Las que traen varias')
                            ->addActionLabel('Agregar presentación')
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->dehydrated(false)
                            ->compact()
                            ->table(fn (Get $get, string $operation): array => self::columnas($get('unidad_medida_id'), $operation === 'create', (bool) $get('gravado')))
                            ->schema([
                                Hidden::make('id'),
                                Select::make('unidad_medida_id')
                                    ->label('Medida')
                                    ->hiddenLabel()
                                    ->options(fn (Get $get): array => UnidadMedida::opciones(is_numeric($get('unidad_medida_id')) ? (int) $get('unidad_medida_id') : null))
                                    ->default(fn (): ?int => UnidadMedida::query()->where('codigo', 'CJ')->value('id'))
                                    ->required()
                                    ->live()
                                    ->disabled(fn (Get $get): bool => self::enUso($get('id'))),
                                TextInput::make('contenido')
                                    ->label(fn (Get $get): string => 'Trae ('.self::codigo($get('../../unidad_medida_id')).')')
                                    ->hiddenLabel()
                                    ->numeric()
                                    ->integer()
                                    ->minValue(2)
                                    ->maxValue(100000)
                                    ->required()
                                    ->live(debounce: 500)
                                    ->disabled(fn (Get $get): bool => self::enUso($get('id'))),
                                Toggle::make('activo')
                                    ->label('Se vende')
                                    ->hiddenLabel()
                                    ->default(true),
                                TextInput::make('costo')
                                    ->label('Costo')
                                    ->hiddenLabel()
                                    ->numeric()
                                    ->minValue(0)
                                    ->prefix('L.')
                                    ->placeholder('Opcional')
                                    ->visibleOn('create'),
                                TextInput::make('precio')
                                    ->label('Precio')
                                    ->hiddenLabel()
                                    ->numeric()
                                    ->minValue(0.01)
                                    ->prefix('L.')
                                    ->placeholder('Opcional')
                                    ->visibleOn('create'),
                            ]),
                    ]),
            ]);
    }

    /**
     * Encabezados de la tabla de presentaciones: medida, cuántas trae de la
     * base, si se vende y (al crear) costo y precio, con ISV si paga ISV.
     *
     * @return list<TableColumn>
     */
    private static function columnas(mixed $baseId, bool $alCrear, bool $gravado): array
    {
        $columnas = [
            TableColumn::make('Medida')->markAsRequired(),
            TableColumn::make('Trae ('.self::codigo($baseId).')')->markAsRequired()->width('9rem'),
            TableColumn::make('Se vende')->width('6rem'),
        ];

        if ($alCrear) {
            $conIsv = $gravado ? ' con ISV' : '';
            $columnas[] = TableColumn::make('Costo'.$conIsv)->width('11rem');
            $columnas[] = TableColumn::make('Precio'.$conIsv)->width('11rem');
        }

        return $columnas;
    }

    /**
     * Para llenar el formulario al editar: si la base se vende y las demás presentaciones.
     *
     * @return array{base_activa: bool, otras: list<array{id: int, unidad_medida_id: int, contenido: int, activo: bool}>}
     */
    public static function presentacionesParaEditar(Producto $producto): array
    {
        $producto->loadMissing('presentaciones');
        $otras = [];

        foreach ($producto->presentaciones as $presentacion) {
            if (! $presentacion->es_base) {
                $otras[] = [
                    'id'               => $presentacion->id,
                    'unidad_medida_id' => $presentacion->unidad_medida_id,
                    'contenido'        => $presentacion->contenido,
                    'activo'           => $presentacion->activo,
                ];
            }
        }

        return [
            'base_activa' => $producto->presentacionBase()->activo,
            'otras'       => $otras,
        ];
    }

    /**
     * Las filas de "otras" como las recibe GuardarPresentaciones, con el
     * costo y el precio que se escribieron (solo al crear).
     *
     * @param array<string, mixed> $datos el estado del formulario
     *
     * @return list<array{id: int|null, unidad_medida_id: int, contenido: int, activo: bool, costo: float|null, precio: float|null}>
     */
    public static function otras(array $datos): array
    {
        $filas = [];

        foreach (array_values((array) ($datos['otras'] ?? [])) as $fila) {
            if (! is_array($fila) || ! is_numeric($fila['unidad_medida_id'] ?? null) || ! is_numeric($fila['contenido'] ?? null)) {
                continue;
            }

            $filas[] = [
                'id'               => is_numeric($fila['id'] ?? null) ? (int) $fila['id'] : null,
                'unidad_medida_id' => (int) $fila['unidad_medida_id'],
                'contenido'        => (int) $fila['contenido'],
                'activo'           => (bool) ($fila['activo'] ?? true),
                'costo'            => self::monto($fila['costo'] ?? null),
                'precio'           => self::monto($fila['precio'] ?? null),
            ];
        }

        return $filas;
    }

    public static function monto(mixed $valor): ?float
    {
        return is_numeric($valor) && (float) $valor > 0 ? (float) $valor : null;
    }

    private static function codigo(mixed $medidaId): string
    {
        if (! is_numeric($medidaId)) {
            return UnidadMedida::BASE;
        }

        return (string) (UnidadMedida::query()->whereKey((int) $medidaId)->value('codigo') ?? UnidadMedida::BASE);
    }

    private static function enUso(mixed $id): bool
    {
        if (! is_numeric($id)) {
            return false;
        }

        $presentacion = Presentacion::query()->find((int) $id);

        return $presentacion instanceof Presentacion && $presentacion->enUso();
    }
}
