<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Schemas;

use App\Domain\Precios\CalculadoraPrecio;
use App\Filament\Resources\Productos\ProductoResource;
use App\Models\Presentacion;
use App\Models\Producto;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Ver producto: sus datos y, por presentación (UN, CJ12, FD6…), si se
 * vende, el precio de venta (calculado o fijo), el costo y la utilidad.
 */
class ProductoInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $verCostos = ProductoResource::puedeVerCostos();

        return $schema
            ->components([
                Section::make('Producto')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('descripcion')
                            ->label('Descripción')
                            ->weight('bold')
                            ->columnSpan(2),
                        TextEntry::make('codigo')
                            ->label('Código')
                            ->copyable(),
                        TextEntry::make('proveedor.nombre')
                            ->label('Proveedor'),
                        TextEntry::make('categoria.nombre')
                            ->label('Categoría'),
                        TextEntry::make('unidadMedida.nombre')
                            ->label('Se cuenta en')
                            ->formatStateUsing(fn (Producto $record): string => "{$record->unidadMedida->codigo} · ".mb_ucfirst(mb_strtolower($record->unidadMedida->nombre))),
                        TextEntry::make('gravado')
                            ->label('ISV')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'G · Gravado 15%' : 'E · Exento')
                            ->color(fn (bool $state): string => $state ? 'info' : 'gray'),
                        TextEntry::make('margen_aplicado')
                            ->label('% de utilidad')
                            ->state(fn (Producto $record): string => self::porcentaje($record->margenAplicado()))
                            ->helperText(fn (Producto $record): string => $record->margen === null ? 'el de su categoría' : 'propio del producto')
                            ->visible($verCostos),
                        TextEntry::make('activo')
                            ->label('Estado')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Activo' : 'Inactivo')
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                    ]),

                Section::make('Presentaciones y precios (todas las bodegas)')
                    ->description(fn (Producto $record): string => ($record->gravado
                        ? 'Precio de venta con ISV incluido. La utilidad es sobre el costo con ISV.'
                        : 'Producto exento: el precio de venta no lleva ISV.').' El costo es uno solo, por '.$record->presentacionBase()->palabra(1).'; el de cada presentación es ese × lo que trae.')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('presentaciones')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->table(array_values(array_filter([
                                TableColumn::make('Presentación'),
                                TableColumn::make('Se vende'),
                                TableColumn::make('Precio de venta'),
                                $verCostos ? TableColumn::make('Costo') : null,
                                $verCostos ? TableColumn::make('Utilidad') : null,
                            ])))
                            ->schema(array_values(array_filter([
                                TextEntry::make('sigla')
                                    ->state(fn (Presentacion $record): string => $record->sigla())
                                    ->helperText(fn (Presentacion $record): string => $record->nombre())
                                    ->weight('bold'),
                                TextEntry::make('activo')
                                    ->badge()
                                    ->formatStateUsing(fn (bool $state): string => $state ? 'Sí' : 'No')
                                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                                TextEntry::make('precio')
                                    ->state(fn (Presentacion $record): string => $record->precio > 0 ? lempiras($record->precioConIsv()) : 'Sin precio')
                                    ->helperText(fn (Presentacion $record): string => ($verCostos && $record->precio_fijo ? 'fijo · ' : '').($record->producto->gravado ? 'sin ISV '.lempiras($record->precio) : 'exento'))
                                    ->weight('bold')
                                    ->color(fn (Presentacion $record): string => $record->precio > 0 ? 'primary' : 'danger'),
                                $verCostos ? TextEntry::make('costo')
                                    ->state(fn (Presentacion $record): string => lempiras($record->costoConIsv(), 4))
                                    ->helperText(fn (Presentacion $record): ?string => $record->producto->gravado ? 'neto '.lempiras($record->costo(), 4) : null) : null,
                                $verCostos ? TextEntry::make('utilidad')
                                    ->state(fn (Presentacion $record): string => self::porcentaje(CalculadoraPrecio::margenReal($record->costo(), $record->precio)))
                                    ->helperText(fn (Presentacion $record): string => $record->precio_fijo ? 'precio fijo' : 'calculado') : null,
                            ]))),
                        TextEntry::make('precio_actualizado_at')
                            ->label('Último cambio')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—')
                            ->helperText(fn (Producto $record): ?string => $record->actualizadoPor?->name),
                    ]),
            ]);
    }

    private static function porcentaje(float $fraccion): string
    {
        return rtrim(rtrim(number_format($fraccion * 100, 2), '0'), '.').'%';
    }
}
