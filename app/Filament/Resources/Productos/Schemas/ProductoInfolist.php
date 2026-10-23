<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Schemas;

use App\Domain\Precios\CalculadoraPrecio;
use App\Filament\Resources\Productos\ProductoResource;
use App\Models\Producto;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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
                        TextEntry::make('unidades_por_caja')
                            ->label('Unidades por caja'),
                        TextEntry::make('gravado')
                            ->label('ISV')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'G · Gravado 15%' : 'E · Exento')
                            ->color(fn (bool $state): string => $state ? 'info' : 'gray'),
                        TextEntry::make('margen_aplicado')
                            ->label('% de utilidad')
                            ->state(fn (Producto $record): string => rtrim(rtrim(number_format($record->margenAplicado() * 100, 2), '0'), '.').'%'
                                .($record->margen === null ? ' (de la categoría)' : ' (propio)'))
                            ->visible($verCostos),
                        TextEntry::make('activo')
                            ->label('Estado')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Activo' : 'Inactivo')
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                    ]),

                Section::make('Precios vigentes (todas las bodegas)')
                    ->description(fn (Producto $record): string => $record->gravado
                        ? 'Precio de venta con ISV incluido. La utilidad es sobre el costo con ISV.'
                        : 'Producto exento: el precio de venta no lleva ISV.')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('precio_venta_unidad')
                            ->label('Precio de venta por unidad')
                            ->state(fn (Producto $record): string => lempiras($record->precioUnidadConIsv()))
                            ->helperText(fn (Producto $record): ?string => $record->gravado ? 'sin ISV '.lempiras($record->precio_unidad) : null)
                            ->size('lg')
                            ->weight('bold')
                            ->color('primary'),
                        TextEntry::make('precio_venta_caja')
                            ->label(fn (Producto $record): string => "Precio de venta por caja de {$record->unidades_por_caja}")
                            ->state(fn (Producto $record): string => lempiras($record->precioCajaConIsv()))
                            ->helperText(fn (Producto $record): ?string => $record->gravado ? 'sin ISV '.lempiras($record->precioCaja()) : null)
                            ->size('lg')
                            ->weight('bold'),
                        TextEntry::make('costo_pagado')
                            ->label(fn (Producto $record): string => $record->gravado ? 'Costo por unidad con ISV' : 'Costo por unidad')
                            ->state(fn (Producto $record): string => lempiras(CalculadoraPrecio::conIsv($record->costo_unidad, $record->gravado), 4))
                            ->helperText(fn (Producto $record): ?string => $record->gravado ? 'neto '.lempiras($record->costo_unidad, 4).' + ISV' : null)
                            ->visible($verCostos),
                        TextEntry::make('precio_actualizado_at')
                            ->label('Último cambio')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—')
                            ->helperText(fn (Producto $record): ?string => $record->actualizadoPor?->name),
                    ]),
            ]);
    }
}
