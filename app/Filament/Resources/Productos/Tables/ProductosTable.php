<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Tables;

use App\Domain\Precios\CalculadoraPrecio;
use App\Filament\Resources\Productos\Actions\ActualizarPrecioAction;
use App\Filament\Resources\Productos\ProductoResource;
use App\Models\Producto;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Lista de precios: una fila por producto con lo que el vendedor necesita
 * de un vistazo (precio por unidad y por caja, con y sin ISV) y, para
 * administración/bodega, el costo y el % de utilidad.
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
                    ->formatStateUsing(fn (float $state): string => lempiras($state, 4))
                    ->description(fn (Producto $record): string => self::porcentaje($record->margenAplicado()).' util.'.($record->margen === null ? '' : ' (propio)'))
                    ->alignEnd()
                    ->color('gray')
                    ->visible($verCostos),
                TextColumn::make('precio_unidad')
                    ->label('Unidad')
                    ->formatStateUsing(fn (float $state): string => lempiras($state))
                    ->description(fn (Producto $record): string => $record->gravado ? 'con ISV '.lempiras($record->precioUnidadConIsv()) : 'exento')
                    ->sortable()
                    ->alignEnd()
                    ->weight('bold')
                    ->color('primary'),
                TextColumn::make('precio_caja')
                    ->label('Caja')
                    ->state(fn (Producto $record): string => lempiras($record->precioCaja()))
                    ->description(fn (Producto $record): string => $record->gravado ? 'con ISV '.lempiras(CalculadoraPrecio::conIsv($record->precioCaja(), true)) : 'exento')
                    ->alignEnd()
                    ->weight('bold'),
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
                    ViewAction::make()->label('Ver e historial'),
                    EditAction::make(),
                ]),
            ]);
    }

    private static function porcentaje(float $margen): string
    {
        return rtrim(rtrim(number_format($margen * 100, 2), '0'), '.').'%';
    }
}
