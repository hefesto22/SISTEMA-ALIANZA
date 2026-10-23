<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Schemas;

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
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('costo_unidad')
                            ->label('Costo por unidad (sin ISV)')
                            ->formatStateUsing(fn (float $state): string => lempiras($state, 4))
                            ->visible($verCostos),
                        TextEntry::make('precio_unidad')
                            ->label('Precio por unidad')
                            ->formatStateUsing(fn (float $state): string => lempiras($state))
                            ->weight('bold'),
                        TextEntry::make('precio_unidad_isv')
                            ->label('Por unidad con ISV')
                            ->state(fn (Producto $record): string => lempiras($record->precioUnidadConIsv())),
                        TextEntry::make('precio_caja')
                            ->label('Precio por caja')
                            ->state(fn (Producto $record): string => lempiras($record->precioCaja()))
                            ->weight('bold'),
                        TextEntry::make('precio_caja_isv')
                            ->label('Por caja con ISV')
                            ->state(fn (Producto $record): string => lempiras($record->precioCajaConIsv())),
                        TextEntry::make('precio_actualizado_at')
                            ->label('Último cambio')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—')
                            ->helperText(fn (Producto $record): ?string => $record->actualizadoPor?->name),
                    ]),
            ]);
    }
}
