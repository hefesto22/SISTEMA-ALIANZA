<?php

declare(strict_types=1);

namespace App\Filament\Resources\Promociones\Schemas;

use App\Enums\UnidadVenta;
use App\Models\Producto;
use App\Models\Promocion;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * "Si el cliente compra [cantidad] [unidad/caja] de X → se lleva [cantidad] de Y
 * gratis / a mitad de precio / con N%". El código se genera solo al guardar.
 */
class PromocionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Promoción')
                    ->columns(3)
                    ->schema([
                        TextInput::make('nombre')
                            ->label('Nombre')
                            ->placeholder('Ej. Butter Toffee + Baby Nutrine')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),
                        TextEntry::make('codigo')
                            ->label('Código')
                            ->state(fn (?Promocion $record): string => $record?->codigo ?? 'Se genera al guardar')
                            ->badge()
                            ->color('primary'),
                        Textarea::make('descripcion')
                            ->label('Nota para el vendedor (opcional)')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Section::make('Si el cliente compra…')
                    ->columns(2)
                    ->schema([
                        self::producto('producto_condicion_id', 'Producto'),
                        TextInput::make('cantidad_condicion')
                            ->label('Cantidad')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->default(1)
                            ->required()
                            ->live(debounce: 500),
                        Select::make('condicion_unidad')
                            ->label('En')
                            ->options(UnidadVenta::class)
                            ->default(UnidadVenta::Unidad->value)
                            ->selectablePlaceholder(false)
                            ->required()
                            ->live(),
                    ]),

                Section::make('…se lleva')
                    ->description('Puede ser el mismo producto: "compre 2 y lleve 1 más gratis".')
                    ->columns(2)
                    ->schema([
                        self::producto('producto_beneficio_id', 'Producto de regalo o con descuento'),
                        TextInput::make('cantidad_beneficio')
                            ->label('Cantidad')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->default(1)
                            ->required()
                            ->live(debounce: 500),
                        Select::make('beneficio_unidad')
                            ->label('En')
                            ->options(UnidadVenta::class)
                            ->default(UnidadVenta::Unidad->value)
                            ->selectablePlaceholder(false)
                            ->required()
                            ->live(),
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
                            }),
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

                Section::make('Vigencia')
                    ->description('Sin fechas, dura hasta que la desactive.')
                    ->columns(3)
                    ->schema([
                        Toggle::make('activa')
                            ->label('Activa')
                            ->default(true)
                            ->onColor('success')
                            ->inline(false),
                        DatePicker::make('vigente_desde')
                            ->label('Desde')
                            ->live(),
                        DatePicker::make('vigente_hasta')
                            ->label('Hasta')
                            ->minDate(fn (Get $get): ?string => filled($get('vigente_desde')) ? (string) $get('vigente_desde') : null),
                    ]),

                TextEntry::make('vista_previa')
                    ->label('Así la ve el vendedor')
                    ->state(fn (Get $get): string => self::vistaPrevia($get))
                    ->weight('semibold')
                    ->color('success'),
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

    private static function producto(string $campo, string $etiqueta): Select
    {
        return Select::make($campo)
            ->label($etiqueta)
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
            ->live()
            ->columnSpanFull();
    }

    private static function vistaPrevia(Get $get): string
    {
        $condicion = filled($get('producto_condicion_id')) ? Producto::query()->whereKey($get('producto_condicion_id'))->first() : null;
        $beneficio = filled($get('producto_beneficio_id')) ? Producto::query()->whereKey($get('producto_beneficio_id'))->first() : null;

        if (! $condicion instanceof Producto || ! $beneficio instanceof Producto) {
            return 'Escoja los dos productos para ver cómo queda.';
        }

        $promocion = new Promocion(self::completar([
            'producto_condicion_id' => $condicion->id,
            'cantidad_condicion'    => max(1, (int) $get('cantidad_condicion')),
            'condicion_unidad'      => self::unidad($get('condicion_unidad')),
            'producto_beneficio_id' => $beneficio->id,
            'cantidad_beneficio'    => max(1, (int) $get('cantidad_beneficio')),
            'beneficio_unidad'      => self::unidad($get('beneficio_unidad')),
            'tipo'                  => $get('tipo'),
            'descuento_porcentaje'  => $get('descuento_porcentaje'),
        ]));

        $promocion->setRelation('productoCondicion', $condicion);
        $promocion->setRelation('productoBeneficio', $beneficio);

        return $promocion->resumen().'.';
    }

    private static function unidad(mixed $valor): UnidadVenta
    {
        if ($valor instanceof UnidadVenta) {
            return $valor;
        }

        return UnidadVenta::tryFrom(is_string($valor) ? $valor : '') ?? UnidadVenta::Unidad;
    }
}
