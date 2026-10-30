<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras\Schemas;

use App\Domain\Precios\CalculadoraPrecio;
use App\Enums\UnidadVenta;
use App\Filament\Support\Acceso;
use App\Models\Bodega;
use App\Models\Producto;
use App\Models\Proveedor;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Registrar una compra como viene en la factura del proveedor: por cada
 * producto, si vino en caja o suelto, cuántas y el costo de cada una CON ISV
 * (si el producto es gravado). Solo salen los productos de ese proveedor.
 *
 * Debajo del costo se ve el costo de hoy, para darse cuenta de un error de
 * dedo antes de guardar.
 */
final class CompraForm
{
    public static function configure(Schema $schema): Schema
    {
        $veTodo = Acceso::veTodo();

        return $schema
            ->columns(1)
            ->components([
                Section::make('Compra')
                    ->columns(['default' => 1, 'md' => 4])
                    ->schema([
                        Select::make('proveedor_id')
                            ->label('Proveedor')
                            ->options(fn (): array => self::proveedores())
                            ->searchable()
                            ->required()
                            // Al cambiarlo se cargan los productos de ese proveedor.
                            ->live()
                            ->columnSpan(['md' => 2]),
                        Select::make('bodega_id')
                            ->label('Entra a la bodega')
                            ->options(fn (): array => Bodega::query()->activas()->orderBy('nombre')->pluck('nombre', 'id')->all())
                            ->default(fn (): ?int => Acceso::usuario()?->bodega_id)
                            ->required()
                            ->visible($veTodo),
                        TextInput::make('factura_proveedor')
                            ->label('Factura del proveedor')
                            ->placeholder('Ej. 000-001-01-00012345')
                            ->maxLength(60),
                        DatePicker::make('fecha')
                            ->default(now())
                            ->maxDate(now())
                            ->required(),
                    ]),
                Section::make('Productos')
                    ->description('Costo como viene en la factura, por caja o por unidad, con ISV si el producto es gravado. Al guardar sube la existencia de la bodega y se recalculan costo y precio de venta.')
                    ->schema([
                        Repeater::make('lineas')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Producto'),
                                TableColumn::make('En')->width('130px'),
                                TableColumn::make('Cantidad')->markAsRequired()->width('120px'),
                                TableColumn::make('Costo c/u con ISV')->markAsRequired()->width('190px'),
                                TableColumn::make('Total')->width('150px'),
                            ])
                            ->schema([
                                Select::make('producto_id')
                                    ->hiddenLabel()
                                    ->options(fn (Get $get): array => self::productosDe($get('../../proveedor_id')))
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->placeholder(fn (Get $get): string => blank($get('../../proveedor_id')) ? 'Escoja primero el proveedor' : 'Buscar producto…'),
                                Select::make('unidad')
                                    ->hiddenLabel()
                                    ->options(UnidadVenta::class)
                                    ->default(UnidadVenta::Caja->value)
                                    ->selectablePlaceholder(false)
                                    ->required()
                                    ->live(),
                                TextInput::make('cantidad')
                                    ->hiddenLabel()
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->required()
                                    ->live(onBlur: true),
                                TextInput::make('costo')
                                    ->hiddenLabel()
                                    ->numeric()
                                    ->minValue(0.01)
                                    ->prefix('L.')
                                    ->required()
                                    ->live(onBlur: true)
                                    ->helperText(fn (Get $get): ?string => self::costoDeHoy($get('producto_id'), $get('unidad'))),
                                TextInput::make('total')
                                    ->hiddenLabel()
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->placeholder(fn (Get $get): string => lempiras(self::numero($get('cantidad')) * self::numero($get('costo')))),
                            ])
                            ->defaultItems(1)
                            ->minItems(1)
                            ->addActionLabel('Agregar producto')
                            ->reorderable(false)
                            ->required(),
                        TextEntry::make('total_compra')
                            ->label('Total de la compra')
                            ->state(fn (Get $get): string => lempiras(self::totalDe($get('lineas'))))
                            ->weight('bold'),
                    ]),
                Textarea::make('notas')
                    ->label('Notas')
                    ->rows(2)
                    ->maxLength(1000),
            ]);
    }

    /**
     * Las líneas del formulario como las recibe RegistrarCompra.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{producto_id: int, unidad: string, cantidad: int, costo: float}>
     */
    public static function lineas(array $data): array
    {
        $lineas = [];

        foreach ((array) ($data['lineas'] ?? []) as $fila) {
            if (! is_array($fila) || ! is_numeric($fila['producto_id'] ?? null)) {
                continue;
            }

            $unidad = $fila['unidad'] ?? UnidadVenta::Caja->value;

            $lineas[] = [
                'producto_id' => (int) $fila['producto_id'],
                'unidad'      => $unidad instanceof UnidadVenta ? $unidad->value : (string) $unidad,
                'cantidad'    => (int) self::numero($fila['cantidad'] ?? 0),
                'costo'       => self::numero($fila['costo'] ?? 0),
            ];
        }

        return $lineas;
    }

    /**
     * "DISTRIBUIDORA X · RTN 08019985012345" (los activos).
     *
     * @return array<int, string>
     */
    private static function proveedores(): array
    {
        /** @var array<int, string> $opciones */
        $opciones = Proveedor::query()
            ->activos()
            ->orderBy('nombre')
            ->get()
            ->mapWithKeys(fn (Proveedor $proveedor): array => [
                $proveedor->id => $proveedor->nombre.(filled($proveedor->rtn) ? " · RTN {$proveedor->rtn}" : ''),
            ])
            ->all();

        return $opciones;
    }

    /**
     * Productos activos del proveedor: [id => "CÓDIGO · DESCRIPCIÓN"].
     *
     * @return array<int, string>
     */
    private static function productosDe(mixed $proveedorId): array
    {
        if (! is_numeric($proveedorId)) {
            return [];
        }

        /** @var array<int, string> $opciones */
        $opciones = Producto::query()
            ->activos()
            ->where('proveedor_id', (int) $proveedorId)
            ->orderBy('descripcion')
            ->get(['id', 'codigo', 'descripcion'])
            ->mapWithKeys(fn (Producto $producto): array => [$producto->id => $producto->etiqueta()])
            ->all();

        return $opciones;
    }

    /**
     * "Hoy: L. 1,380.00 la caja" (con ISV si es gravado).
     */
    private static function costoDeHoy(mixed $productoId, mixed $unidad): ?string
    {
        $producto = is_numeric($productoId) ? Producto::query()->find((int) $productoId) : null;

        if (! $producto instanceof Producto) {
            return null;
        }

        $unidad = $unidad instanceof UnidadVenta ? $unidad : (UnidadVenta::tryFrom(is_string($unidad) ? $unidad : '') ?? UnidadVenta::Caja);
        $costo = CalculadoraPrecio::aPantalla($producto->costo_unidad, $producto->gravado, $producto->unidadesEn($unidad));

        return 'Hoy: '.lempiras($costo).($unidad === UnidadVenta::Caja ? " la caja de {$producto->unidades_por_caja}" : ' la unidad');
    }

    private static function totalDe(mixed $lineas): float
    {
        $total = 0.0;

        foreach (is_array($lineas) ? $lineas : [] as $fila) {
            if (is_array($fila)) {
                $total += round(self::numero($fila['cantidad'] ?? 0) * self::numero($fila['costo'] ?? 0), 2);
            }
        }

        return $total;
    }

    private static function numero(mixed $valor): float
    {
        return is_numeric($valor) ? (float) $valor : 0.0;
    }
}
