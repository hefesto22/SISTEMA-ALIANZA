<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras\Tables;

use App\Filament\Support\Acceso;
use App\Models\Compra;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Compras registradas, la más reciente arriba.
 */
final class ComprasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('numero')
                    ->label('Número')
                    ->searchable()
                    ->weight('semibold'),
                TextColumn::make('fecha')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('proveedor.nombre')
                    ->label('Proveedor')
                    ->searchable()
                    ->description(fn (Compra $record): ?string => $record->factura_proveedor === null ? null : 'Factura '.$record->factura_proveedor),
                TextColumn::make('bodega.nombre')
                    ->label('Bodega')
                    ->visible(fn (): bool => Acceso::veTodo()),
                TextColumn::make('total')
                    ->formatStateUsing(fn (float $state): string => lempiras($state))
                    ->alignEnd()
                    ->weight('bold')
                    ->sortable(),
                TextColumn::make('usuario.name')
                    ->label('Registró')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->searchPlaceholder('Número o proveedor')
            ->filters([
                SelectFilter::make('proveedor_id')
                    ->label('Proveedor')
                    ->relationship('proveedor', 'nombre')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->relationship('bodega', 'nombre')
                    ->visible(fn (): bool => Acceso::veTodo()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
