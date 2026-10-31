<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras\Schemas;

use App\Enums\UnidadVenta;
use App\Filament\Schemas\Components\LineasCompra;
use App\Filament\Support\Acceso;
use App\Models\Bodega;
use App\Models\Proveedor;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Registrar una compra como viene en la factura del proveedor. Arriba el
 * proveedor y la factura; abajo los productos como lista tipo carrito
 * (LineasCompra): solo salen los de ese proveedor, se toca uno y entra con
 * el costo de hoy, en caja o suelto, cuántas y el costo de cada una CON ISV
 * (si el producto es gravado). Si el costo se aleja mucho del de hoy, avisa
 * antes de guardar. "Registrar compra" va en la barra del total.
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
                    ->icon(Heroicon::OutlinedTruck)
                    ->columns(['default' => 1, 'md' => $veTodo ? 4 : 3])
                    ->extraAttributes(['class' => 'pv'])
                    ->schema([
                        Select::make('proveedor_id')
                            ->label('Proveedor')
                            ->options(fn (): array => self::proveedores())
                            ->searchable()
                            ->required()
                            // Los productos que se buscan abajo son de este proveedor.
                            ->live()
                            ->columnSpan(['md' => $veTodo ? 2 : 1]),
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
                            ->label('Fecha de la factura')
                            ->default(now())
                            ->maxDate(now())
                            ->required(),
                    ]),
                Section::make('Productos')
                    ->description('Toque el producto para agregarlo. Costo como viene en la factura, con ISV si es gravado. Al registrar sube la existencia y se recalculan costo y precio de venta.')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->extraAttributes(['class' => 'pv'])
                    ->schema([
                        LineasCompra::make('lineas')
                            ->label('Productos')
                            ->hiddenLabel(),
                    ]),
                Section::make('Notas')
                    ->icon(Heroicon::OutlinedChatBubbleBottomCenterText)
                    ->compact()
                    ->extraAttributes(['class' => 'pv'])
                    ->schema([
                        Textarea::make('notas')
                            ->hiddenLabel()
                            ->placeholder('Ej. Faltó una caja, el proveedor la manda el lunes…')
                            ->rows(2)
                            ->autosize()
                            ->maxLength(1000),
                    ]),
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

    private static function numero(mixed $valor): float
    {
        return is_numeric($valor) ? (float) $valor : 0.0;
    }
}
