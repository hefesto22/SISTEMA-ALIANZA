<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras\Schemas;

use App\Domain\Precios\CalculadoraPrecio;
use App\Models\Compra;
use App\Models\CompraItem;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * La compra registrada y cómo quedó cada producto: costo de la factura,
 * costo nuevo por unidad y precio de venta nuevo de esa presentación (con
 * ISV si es gravado).
 */
final class CompraInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Compra')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('numero')
                            ->label('Número')
                            ->weight('bold')
                            ->copyable(),
                        TextEntry::make('fecha')
                            ->date('d/m/Y'),
                        TextEntry::make('bodega.nombre')
                            ->label('Entró a'),
                        TextEntry::make('proveedor.nombre')
                            ->label('Proveedor')
                            ->helperText(fn (Compra $record): ?string => filled($record->proveedor->rtn) ? 'RTN '.$record->proveedor->rtn : null),
                        TextEntry::make('factura_proveedor')
                            ->label('Factura del proveedor')
                            ->placeholder('—'),
                        TextEntry::make('usuario.name')
                            ->label('Registró')
                            ->placeholder('—')
                            ->helperText(fn (Compra $record): ?string => $record->created_at?->format('d/m/Y H:i')),
                        TextEntry::make('subtotal')
                            ->formatStateUsing(fn (float $state): string => lempiras($state)),
                        TextEntry::make('isv')
                            ->label('ISV')
                            ->formatStateUsing(fn (float $state): string => lempiras($state)),
                        TextEntry::make('total')
                            ->formatStateUsing(fn (float $state): string => lempiras($state))
                            ->weight('bold'),
                        TextEntry::make('notas')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Productos')
                    ->description('Con ISV si el producto es gravado. El costo nuevo es por unidad; el precio nuevo, de la presentación en que entró (sale del costo nuevo con el margen del producto, salvo precio fijo).')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Producto'),
                                TableColumn::make('Entró'),
                                TableColumn::make('Costo factura'),
                                TableColumn::make('Total'),
                                TableColumn::make('Costo unidad'),
                                TableColumn::make('Precio de venta'),
                            ])
                            ->schema([
                                TextEntry::make('producto.descripcion')
                                    ->helperText(fn (CompraItem $record): string => $record->producto->codigo),
                                TextEntry::make('cantidad')
                                    ->formatStateUsing(fn (CompraItem $record): string => $record->cantidad.' '.$record->presentacion->sigla())
                                    ->helperText(fn (CompraItem $record): ?string => $record->presentacion->contenido > 1 ? "{$record->unidades} unidades" : null),
                                TextEntry::make('costo_ingresado')
                                    ->formatStateUsing(fn (float $state): string => lempiras($state))
                                    ->helperText(fn (CompraItem $record): string => 'por '.$record->presentacion->palabra(1)),
                                TextEntry::make('total')
                                    ->formatStateUsing(fn (float $state): string => lempiras($state))
                                    ->weight('bold'),
                                TextEntry::make('costo_nuevo')
                                    ->formatStateUsing(fn (CompraItem $record): string => lempiras(CalculadoraPrecio::conIsv($record->costo_nuevo, $record->gravado), 4))
                                    ->helperText(fn (CompraItem $record): ?string => $record->costo_anterior === null ? null : 'antes '.lempiras(CalculadoraPrecio::conIsv($record->costo_anterior, $record->gravado), 4)),
                                TextEntry::make('precio_nuevo')
                                    ->formatStateUsing(fn (CompraItem $record): string => lempiras(CalculadoraPrecio::conIsv($record->precio_nuevo, $record->gravado)))
                                    ->helperText(fn (CompraItem $record): string => $record->presentacion->sigla())
                                    ->weight('bold')
                                    ->color('primary'),
                            ]),
                    ]),
            ]);
    }
}
