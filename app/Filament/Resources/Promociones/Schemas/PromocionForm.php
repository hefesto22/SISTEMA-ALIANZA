<?php

declare(strict_types=1);

namespace App\Filament\Resources\Promociones\Schemas;

use App\Enums\UnidadVenta;
use App\Models\Producto;
use App\Models\Promocion;
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

                    Section::make('Se lleva')
                        ->icon(Heroicon::OutlinedGift)
                        ->compact()
                        ->columns(4)
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
            'nombre'   => trim((string) $get('nombre')),
            'codigo'   => $record->codigo ?? null,
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
            'nota' => trim((string) $get('descripcion')),
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
