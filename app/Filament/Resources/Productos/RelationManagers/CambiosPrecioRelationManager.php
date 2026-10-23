<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\RelationManagers;

use App\Filament\Resources\Productos\ProductoResource;
use App\Models\CambioPrecio;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Historial de precios del producto: quién, cuándo, desde qué bodega y de cuánto a cuánto.
 */
class CambiosPrecioRelationManager extends RelationManager
{
    protected static string $relationship = 'cambiosPrecio';

    protected static ?string $title = 'Historial de precios';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $verCostos = ProductoResource::puedeVerCostos();

        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('motivo')
                    ->badge(),
                TextColumn::make('costo')
                    ->label('Costo unidad')
                    ->state(fn (CambioPrecio $record): string => ($record->costo_anterior === null ? '' : lempiras($record->costo_anterior, 4).' → ').lempiras($record->costo_nuevo, 4))
                    ->visible($verCostos),
                TextColumn::make('precio')
                    ->label('Precio unidad')
                    ->state(fn (CambioPrecio $record): string => ($record->precio_anterior === null ? '' : lempiras($record->precio_anterior).' → ').lempiras($record->precio_nuevo))
                    ->weight('bold'),
                TextColumn::make('variacion')
                    ->label('Variación')
                    ->state(fn (CambioPrecio $record): ?string => ($v = $record->variacionPrecio()) === null ? null : ($v > 0 ? '+' : '').number_format($v, 2).'%')
                    ->color(fn (CambioPrecio $record): string => ($record->variacionPrecio() ?? 0) > 0 ? 'danger' : 'success')
                    ->placeholder('—'),
                TextColumn::make('margen')
                    ->label('% Util.')
                    ->formatStateUsing(fn (float $state): string => rtrim(rtrim(number_format($state * 100, 2), '0'), '.').'%')
                    ->visible($verCostos),
                TextColumn::make('usuario.name')
                    ->label('Quién')
                    ->placeholder('Sistema'),
                TextColumn::make('bodega.nombre')
                    ->label('Desde')
                    ->placeholder('—'),
                TextColumn::make('nota')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
