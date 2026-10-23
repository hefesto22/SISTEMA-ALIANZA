<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Tables;

use App\Filament\Resources\Pedidos\Actions\AnularPedidoAction;
use App\Filament\Resources\Pedidos\Actions\ImprimirPedidoAction;
use App\Filament\Resources\Pedidos\Actions\ValidarDespachoAction;
use App\Filament\Support\Acceso;
use App\Models\Pedido;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PedidosTable
{
    public static function configure(Table $table): Table
    {
        $veTodo = Acceso::veTodo();
        $esVendedor = Acceso::esVendedor();

        return $table
            ->columns([
                TextColumn::make('numero')
                    ->label('Pedido')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('fecha')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('cliente.nombre')
                    ->label('Cliente')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('ruta')
                    ->label('Ruta')
                    ->state(fn (Pedido $record): ?string => $record->fuera_de_ruta ? 'Fuera de ruta' : null)
                    ->badge()
                    ->color('warning')
                    ->tooltip(fn (Pedido $record): ?string => $record->motivo_fuera_ruta)
                    ->placeholder(''),
                TextColumn::make('vendedor.name')
                    ->label('Vendedor')
                    ->visible(! $esVendedor)
                    ->visibleFrom('md'),
                TextColumn::make('bodega.codigo')
                    ->label('Bodega')
                    ->badge()
                    ->color('gray')
                    ->visible($veTodo),
                TextColumn::make('estado')
                    ->badge(),
                TextColumn::make('items_count')
                    ->label('Líneas')
                    ->alignCenter()
                    ->visibleFrom('md'),
                TextColumn::make('total')
                    ->formatStateUsing(fn (float $state): string => lempiras($state))
                    ->alignEnd()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('validado_at')
                    ->label('Despachado')
                    ->since()
                    ->dateTimeTooltip('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->relationship('bodega', 'nombre')
                    ->visible($veTodo),
                SelectFilter::make('vendedor_id')
                    ->label('Vendedor')
                    ->relationship('vendedor', 'name')
                    ->searchable()
                    ->visible(! $esVendedor),
                TernaryFilter::make('fuera_de_ruta')
                    ->label('Fuera de ruta'),
                Filter::make('fecha')
                    ->schema([
                        DatePicker::make('desde'),
                        DatePicker::make('hasta'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['desde'] ?? null, fn (Builder $q, mixed $fecha): Builder => $q->whereDate('fecha', '>=', $fecha))
                        ->when($data['hasta'] ?? null, fn (Builder $q, mixed $fecha): Builder => $q->whereDate('fecha', '<=', $fecha))),
            ])
            ->recordActions([
                ValidarDespachoAction::make(),
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    ImprimirPedidoAction::make(),
                    AnularPedidoAction::make(),
                ]),
            ]);
    }
}
