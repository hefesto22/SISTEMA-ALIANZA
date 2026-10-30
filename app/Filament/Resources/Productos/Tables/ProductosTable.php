<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Tables;

use App\Domain\Precios\CalculadoraPrecio;
use App\Filament\Resources\Productos\Actions\ActualizarPrecioAction;
use App\Filament\Resources\Productos\Actions\AjustarExistenciaAction;
use App\Filament\Resources\Productos\ProductoResource;
use App\Filament\Support\Acceso;
use App\Models\Existencia;
use App\Models\Producto;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lista de precios: una fila por producto con lo que el vendedor necesita
 * de un vistazo. El precio grande es el de venta al cliente (con ISV si es
 * gravado); abajo, chiquito, el precio sin ISV. Administración/bodega ven
 * además el costo (con ISV si es gravado) y la utilidad que de verdad deja.
 */
class ProductosTable
{
    public static function configure(Table $table): Table
    {
        $verCostos = ProductoResource::puedeVerCostos();

        return $table
            ->columns([
                TextColumn::make('descripcion')
                    ->label('Producto')
                    ->searchable(['descripcion', 'codigo'])
                    ->sortable()
                    ->weight('semibold')
                    ->wrap()
                    ->description(fn (Producto $record): string => "{$record->codigo} · {$record->categoria->nombre}"),
                TextColumn::make('codigo')
                    ->label('Código')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('proveedor_etiqueta')
                    ->label('Proveedor')
                    ->state(fn (Producto $record): string => $record->proveedor->etiqueta())
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('unidades_por_caja')
                    ->label('UxC')
                    ->alignCenter()
                    ->color('gray'),
                TextColumn::make('gravado')
                    ->label('ISV')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'G' : 'E')
                    ->color(fn (bool $state): string => $state ? 'info' : 'gray')
                    ->tooltip(fn (Producto $record): string => $record->gravado ? 'Gravado 15%' : 'Exento')
                    ->alignCenter(),
                TextColumn::make('costo_unidad')
                    ->label('Costo')
                    ->formatStateUsing(fn (Producto $record): string => lempiras(CalculadoraPrecio::conIsv($record->costo_unidad, $record->gravado), 4))
                    ->description(fn (Producto $record): string => self::porcentaje(CalculadoraPrecio::margenReal($record->costo_unidad, $record->precio_unidad)).' util.')
                    ->tooltip(fn (Producto $record): ?string => $record->gravado ? 'Costo por unidad con ISV' : null)
                    ->alignEnd()
                    ->color('gray')
                    ->visible($verCostos),
                TextColumn::make('precio_unidad')
                    ->label('Unidad')
                    ->formatStateUsing(fn (Producto $record): string => lempiras($record->precioUnidadConIsv()))
                    ->description(fn (Producto $record): string => $record->gravado ? 'sin ISV '.lempiras($record->precio_unidad) : 'exento')
                    ->sortable()
                    ->alignEnd()
                    ->weight('bold')
                    ->color('primary'),
                TextColumn::make('precio_caja')
                    ->label('Caja')
                    ->state(fn (Producto $record): string => lempiras($record->precioCajaConIsv()))
                    ->description(fn (Producto $record): string => $record->gravado ? 'sin ISV '.lempiras($record->precioCaja()) : 'exento')
                    ->alignEnd()
                    ->weight('bold'),
                TextColumn::make('existencia')
                    ->label('Existencia')
                    ->state(fn (Producto $record): string => Existencia::enCajas(self::existencia($record), $record->unidades_por_caja))
                    ->description(fn (): ?string => Acceso::veTodo() ? 'todas las bodegas' : null)
                    ->color(fn (Producto $record): string => self::existencia($record) > 0 ? 'success' : 'danger')
                    ->weight('semibold')
                    ->alignEnd()
                    // Es la suma que trae la consulta (alias), no una columna de productos.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('existencia', $direction === 'desc' ? 'desc' : 'asc')),
                TextColumn::make('precio_actualizado_at')
                    ->label('Actualizado')
                    ->since()
                    ->dateTimeTooltip('d/m/Y H:i')
                    ->sortable()
                    ->color('gray')
                    ->toggleable(),
            ])
            ->defaultSort('descripcion')
            ->striped()
            ->paginated([25, 50, 100])
            ->searchPlaceholder('Código o nombre del producto')
            ->filters([
                SelectFilter::make('categoria_id')
                    ->label('Categoría')
                    ->relationship('categoria', 'nombre')
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('gravado')
                    ->label('ISV')
                    ->trueLabel('Gravados (G)')
                    ->falseLabel('Exentos (E)'),
                TernaryFilter::make('activo')
                    ->label('Estado')
                    ->trueLabel('Activos')
                    ->falseLabel('Inactivos')
                    ->default(true),
            ])
            ->recordActions([
                ActualizarPrecioAction::make()->label('Actualizar'),
                ActionGroup::make([
                    ViewAction::make()->label('Ver, historial y kárdex'),
                    AjustarExistenciaAction::make(),
                    EditAction::make(),
                ]),
            ]);
    }

    /**
     * La existencia que trae la consulta (ProductoResource::getEloquentQuery).
     */
    private static function existencia(Producto $producto): int
    {
        $existencia = $producto->getAttribute('existencia');

        return is_numeric($existencia) ? (int) $existencia : 0;
    }

    private static function porcentaje(float $margen): string
    {
        return rtrim(rtrim(number_format($margen * 100, 2), '0'), '.').'%';
    }
}
