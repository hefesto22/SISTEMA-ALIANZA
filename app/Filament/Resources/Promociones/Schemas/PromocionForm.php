<?php

declare(strict_types=1);

namespace App\Filament\Resources\Promociones\Schemas;

use App\Enums\UnidadVenta;
use App\Models\Producto;
use App\Models\Promocion;
use App\Models\PromocionCondicion;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Todo en una pantalla, sin tanto scroll:
 *
 *   Nombre · Desde · Hasta · Activa
 *   [Si el cliente compra…: uno o varios productos]  [ …se lleva: producto, cantidad, cómo sale ]
 *   Nota para el vendedor
 *   Vista previa
 *
 * El código (PROMO-0001) se genera solo al guardar.
 */
class PromocionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(12)
            ->components([
                TextInput::make('nombre')
                    ->label('Nombre')
                    ->placeholder('Ej. Toffee + Nutrine')
                    ->required()
                    ->maxLength(255)
                    ->columnSpan(6),
                DatePicker::make('vigente_desde')
                    ->label('Desde')
                    ->live()
                    ->columnSpan(2),
                DatePicker::make('vigente_hasta')
                    ->label('Hasta')
                    ->minDate(fn (Get $get): ?string => filled($get('vigente_desde')) ? (string) $get('vigente_desde') : null)
                    ->columnSpan(2),
                Toggle::make('activa')
                    ->label('Activa')
                    ->default(true)
                    ->onColor('success')
                    ->inline(false)
                    ->columnSpan(2),

                Fieldset::make('Si el cliente compra…')
                    ->columns(1)
                    ->columnSpan(7)
                    ->schema([
                        Repeater::make('condiciones')
                            ->hiddenLabel()
                            ->relationship()
                            ->table([
                                TableColumn::make('Producto')->markAsRequired(),
                                TableColumn::make('Cantidad')->markAsRequired()->width('110px'),
                                TableColumn::make('En')->width('130px'),
                            ])
                            ->schema([
                                self::producto('producto_id')
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

                Fieldset::make('…se lleva')
                    ->columns(2)
                    ->columnSpan(5)
                    ->schema([
                        self::producto('producto_beneficio_id')
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
                                'mitad'  => 'Mitad',
                                'otro'   => 'Otro %',
                            ])
                            ->icons([
                                'gratis' => Heroicon::OutlinedGift,
                                'mitad'  => Heroicon::OutlinedReceiptPercent,
                                'otro'   => Heroicon::OutlinedAdjustmentsHorizontal,
                            ])
                            ->colors([
                                'gratis' => 'success',
                                'mitad'  => 'warning',
                                'otro'   => 'gray',
                            ])
                            ->inline()
                            ->default('gratis')
                            ->required()
                            ->live()
                            ->afterStateHydrated(function (ToggleButtons $component, ?Promocion $record): void {
                                if ($record instanceof Promocion) {
                                    $component->state($record->tipo());
                                }
                            })
                            ->columnSpanFull(),
                        TextInput::make('descuento_porcentaje')
                            ->label('% de descuento')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->suffix('%')
                            ->required(fn (Get $get): bool => $get('tipo') === 'otro')
                            ->visible(fn (Get $get): bool => $get('tipo') === 'otro')
                            ->live(debounce: 500),
                    ]),

                TextInput::make('descripcion')
                    ->label('Nota para el vendedor (opcional)')
                    ->placeholder('Ej. Válida solo para pulperías')
                    ->maxLength(500)
                    ->columnSpanFull(),

                TextEntry::make('vista_previa')
                    ->label('Así la ve el vendedor')
                    ->state(fn (Get $get): string => self::vistaPrevia($get))
                    ->icon(Heroicon::OutlinedGift)
                    ->iconColor('success')
                    ->weight('semibold')
                    ->columnSpanFull(),
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

    private static function producto(string $campo): Select
    {
        return Select::make($campo)
            ->placeholder('Buscar por código o nombre…')
            ->searchable()
            ->getSearchResultsUsing(fn (string $search): array => Producto::query()
                ->activos()
                ->buscar($search)
                ->orderBy('descripcion')
                ->limit(50)
                ->get()
                ->mapWithKeys(fn (Producto $p): array => [$p->id => $p->etiqueta()])
                ->all())
            ->getOptionLabelUsing(fn (mixed $value): ?string => Producto::query()->whereKey($value)->first()?->etiqueta())
            ->required()
            ->live();
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
     * Arma la promoción en memoria (sin guardar) para mostrar cómo se lee.
     */
    private static function vistaPrevia(Get $get): string
    {
        $filas = $get('condiciones');
        $filas = is_array($filas) ? $filas : [];
        $ids = collect($filas)->pluck('producto_id')->push($get('producto_beneficio_id'))->filter()->unique()->all();
        $productos = Producto::query()->whereKey($ids)->get()->keyBy('id');

        /** @var Collection<int, PromocionCondicion> $condiciones */
        $condiciones = new Collection;

        foreach ($filas as $fila) {
            $producto = is_array($fila) ? $productos->get($fila['producto_id'] ?? null) : null;

            if (! is_array($fila) || ! $producto instanceof Producto) {
                continue;
            }

            $condicion = new PromocionCondicion([
                'producto_id' => $producto->id,
                'cantidad'    => max(1, (int) ($fila['cantidad'] ?? 1)),
                'unidad'      => self::unidadDe($fila['unidad'] ?? null),
            ]);
            $condicion->setRelation('producto', $producto);
            $condiciones->push($condicion);
        }

        $regalo = $productos->get($get('producto_beneficio_id'));

        if ($condiciones->isEmpty() || ! $regalo instanceof Producto) {
            return 'Escoja lo que compra y lo que se lleva para ver cómo queda.';
        }

        $promocion = new Promocion(self::completar([
            'producto_beneficio_id' => $regalo->id,
            'cantidad_beneficio'    => max(1, (int) $get('cantidad_beneficio')),
            'beneficio_unidad'      => self::unidadDe($get('beneficio_unidad')),
            'tipo'                  => $get('tipo'),
            'descuento_porcentaje'  => $get('descuento_porcentaje'),
        ]));

        $promocion->setRelation('condiciones', $condiciones);
        $promocion->setRelation('productoBeneficio', $regalo);

        return $promocion->resumen().'.';
    }

    private static function unidadDe(mixed $valor): UnidadVenta
    {
        if ($valor instanceof UnidadVenta) {
            return $valor;
        }

        return UnidadVenta::tryFrom(is_string($valor) ? $valor : '') ?? UnidadVenta::Unidad;
    }
}
