<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Tables;

use App\Domain\Precios\CalculadoraPrecio;
use App\Filament\Resources\Productos\Actions\ActualizarPrecioAction;
use App\Filament\Resources\Productos\Actions\AjustarExistenciaAction;
use App\Filament\Resources\Productos\ProductoResource;
use App\Filament\Support\Acceso;
use App\Models\Presentacion;
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
 * de un vistazo: el precio de venta al cliente de cada presentación que se
 * vende (con ISV si es gravado): "UN L 12.39", "CJ12 L 148.68"…; el fijo
 * (puesto a mano, las compras no lo cambian) lleva "fijo". Administración y
 * bodega ven además el costo por unidad (con ISV si es gravado).
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

                TextColumn::make('gravado')
                    ->label('ISV')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'G' : 'E')
                    ->color(fn (bool $state): string => $state ? 'info' : 'gray')
                    ->tooltip(fn (Producto $record): string => $record->gravado ? 'Gravado 15%' : 'Exento')
                    ->alignCenter(),
                TextColumn::make('costos')
                    ->label('Costo')
                    ->state(fn (Producto $record): array => self::costos($record))
                    ->listWithLineBreaks()
                    ->description(fn (Producto $record): string => self::porcentaje($record->margenAplicado()).' util.')
                    ->tooltip(fn (Producto $record): ?string => $record->gravado ? 'Costo promedio de cada presentación, con ISV' : 'Costo promedio de cada presentación')
                    ->alignEnd()
                    ->color('gray')
                    ->visible($verCostos),
                TextColumn::make('precios')
                    ->label('Precios')
                    ->state(fn (Producto $record): array => self::precios($record, $verCostos))
                    ->listWithLineBreaks()
                    ->html()
                    ->alignEnd()
                    ->weight('bold')
                    ->color('primary'),
                TextColumn::make('existencia')
                    ->label('Existencia')
                    ->state(fn (Producto $record): string => $record->existenciaPorPresentacion(self::porPresentacion($record)))
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
                TernaryFilter::make('precio_fijo')
                    ->label('Precio de venta')
                    ->trueLabel('Con alguno fijo (puesto a mano)')
                    ->falseLabel('Todos calculados con el %')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('presentaciones', fn (Builder $q): Builder => $q->where('precio_fijo', true)),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('presentaciones', fn (Builder $q): Builder => $q->where('precio_fijo', true)),
                    )
                    ->visible($verCostos),
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
     * "UN · L 12.39", "CJ12 · L 148.68 · fijo" de las que se venden (sin
     * precio: "CJ12 · sin precio").
     *
     * @return list<string>
     */
    private static function precios(Producto $producto, bool $verCostos): array
    {
        return array_values($producto->presentacionesActivas()
            ->map(fn (Presentacion $presentacion): string => e($presentacion->sigla()).' · '
                .($presentacion->precio > 0 ? e(lempiras($presentacion->precioConIsv())) : '<span style="color:var(--danger-600)">sin precio</span>')
                .($verCostos && $presentacion->precio_fijo ? ' <span style="opacity:.6;font-weight:500">· fijo</span>' : ''))
            ->all());
    }

    /**
     * "UN · L 10.65", "CJ48 · L 511.26": el costo promedio de cada una que se vende.
     *
     * @return list<string>
     */
    private static function costos(Producto $producto): array
    {
        return array_values($producto->presentacionesActivas()
            ->map(fn (Presentacion $presentacion): string => $presentacion->sigla().' · '.lempiras(CalculadoraPrecio::conIsv($presentacion->costo, $producto->gravado)))
            ->all());
    }

    /**
     * Lo que hay de cada presentación (las existencias que trae la consulta:
     * de su bodega o de todas).
     *
     * @return array<int, int>
     */
    private static function porPresentacion(Producto $producto): array
    {
        $cantidades = [];

        foreach ($producto->existencias as $existencia) {
            $cantidades[$existencia->presentacion_id] = ($cantidades[$existencia->presentacion_id] ?? 0) + $existencia->cantidad;
        }

        return $cantidades;
    }

    /**
     * La existencia total en unidades base que trae la consulta (ProductoResource::getEloquentQuery).
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
