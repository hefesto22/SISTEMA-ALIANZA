<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\RelationManagers;

use App\Domain\Precios\CalculadoraPrecio;
use App\Enums\TipoMovimiento;
use App\Filament\Resources\Productos\ProductoResource;
use App\Filament\Support\Acceso;
use App\Models\Existencia;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Kárdex del producto: cada entrada y salida de cada presentación, en qué
 * bodega, cuánto quedó de ella, a qué costo y de dónde vino (compra,
 * pedido, ajuste, caja abierta). Arriba, lo que hay de cada presentación en
 * cada bodega. El encargado y el vendedor ven solo su bodega.
 */
class MovimientosRelationManager extends RelationManager
{
    protected static string $relationship = 'movimientos';

    protected static ?string $title = 'Existencia y kárdex';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $verCostos = ProductoResource::puedeVerCostos();
        $usuario = Acceso::usuario();
        $todas = $usuario === null || $usuario->veTodasLasBodegas();
        $bodegaId = $usuario?->bodega_id;

        /** @var Producto $producto */
        $producto = $this->getOwnerRecord();

        return $table
            ->description(fn (): string => self::existencias($producto, $todas ? null : ($bodegaId ?? 0)))
            ->modifyQueryUsing(fn (Builder $query): Builder => ($todas ? $query : $query->where('bodega_id', $bodegaId))
                ->with(['bodega', 'compra', 'pedido', 'usuario', 'presentacion.unidadMedida']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('bodega.nombre')
                    ->label('Bodega')
                    ->visible($todas),
                TextColumn::make('tipo')
                    ->badge(),
                TextColumn::make('cantidad')
                    ->label('Entró / salió')
                    ->formatStateUsing(fn (int $state, MovimientoInventario $record): string => ($state > 0 ? '+' : '').$state.' '.$record->presentacion->sigla())
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger')
                    ->weight('semibold'),
                TextColumn::make('existencia')
                    ->label('Quedó')
                    ->formatStateUsing(fn (int $state, MovimientoInventario $record): string => $state.' '.$record->presentacion->sigla())
                    ->color(fn (int $state): ?string => $state < 0 ? 'danger' : null),
                TextColumn::make('costo_unidad')
                    ->label('Costo')
                    ->formatStateUsing(fn (float $state): string => lempiras(CalculadoraPrecio::conIsv($state, $producto->gravado), 4))
                    ->description(fn (MovimientoInventario $record): string => 'cada '.$record->presentacion->sigla())
                    ->color('gray')
                    ->visible($verCostos),
                TextColumn::make('referencia')
                    ->label('De')
                    ->state(fn (MovimientoInventario $record): ?string => $record->compra->numero ?? $record->pedido->numero ?? null)
                    ->placeholder('—'),
                TextColumn::make('usuario.name')
                    ->label('Quién')
                    ->placeholder('Sistema'),
                TextColumn::make('nota')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->relationship('bodega', 'nombre')
                    ->visible($todas),
                SelectFilter::make('tipo')
                    ->options(TipoMovimiento::class),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * "Hay: SANTA ROSA DE COPÁN 2 CJ48 · 3 CJ32 · 5 UN — OCOTEPEQUE 0".
     */
    private static function existencias(Producto $producto, ?int $soloBodega): string
    {
        $query = $producto->existencias()->with('bodega')->orderBy('bodega_id');

        if ($soloBodega !== null) {
            $query->where('bodega_id', $soloBodega);
        }

        $partes = $query->get()
            ->groupBy('bodega_id')
            ->map(fn (Collection $existencias): string => $existencias->first()?->bodega->nombre.' '.$producto->existenciaPorPresentacion(
                $existencias->mapWithKeys(fn (Existencia $existencia): array => [$existencia->presentacion_id => $existencia->cantidad])->all(),
            ))
            ->values()
            ->all();

        return $partes === [] ? 'Todavía no hay existencia registrada.' : 'Hay: '.implode(' — ', $partes);
    }
}
