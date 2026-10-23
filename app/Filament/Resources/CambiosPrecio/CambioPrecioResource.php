<?php

declare(strict_types=1);

namespace App\Filament\Resources\CambiosPrecio;

use App\Enums\MotivoCambioPrecio;
use App\Filament\Resources\CambiosPrecio\Pages\ListCambiosPrecio;
use App\Filament\Resources\Productos\ProductoResource;
use App\Models\CambioPrecio;
use App\Models\Proveedor;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Historial general de cambios de precio (solo lectura).
 */
class CambioPrecioResource extends Resource
{
    protected static ?string $model = CambioPrecio::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $modelLabel = 'Cambio de precio';

    protected static ?string $pluralModelLabel = 'Historial de precios';

    protected static ?string $navigationLabel = 'Historial de precios';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $slug = 'historial-precios';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Precios';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['producto.proveedor', 'usuario', 'bodega']);
    }

    public static function table(Table $table): Table
    {
        $verCostos = ProductoResource::puedeVerCostos();

        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('producto.descripcion')
                    ->label('Producto')
                    ->description(fn (CambioPrecio $record): string => $record->producto->codigo.' · '.$record->producto->proveedor->etiqueta())
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'producto',
                        fn (Builder $q): Builder => $q->where('descripcion', 'ilike', "%{$search}%")->orWhere('codigo', 'ilike', "%{$search}%"),
                    ))
                    ->wrap()
                    ->url(fn (CambioPrecio $record): string => ProductoResource::getUrl('view', ['record' => $record->producto_id])),
                TextColumn::make('motivo')
                    ->badge(),
                TextColumn::make('costo')
                    ->label('Costo unidad')
                    ->state(fn (CambioPrecio $record): string => ($record->costo_anterior === null ? '' : lempiras($record->costo_anterior, 4).' → ').lempiras($record->costo_nuevo, 4))
                    ->visible($verCostos)
                    ->toggleable(),
                TextColumn::make('precio')
                    ->label('Precio unidad')
                    ->state(fn (CambioPrecio $record): string => ($record->precio_anterior === null ? '' : lempiras($record->precio_anterior).' → ').lempiras($record->precio_nuevo))
                    ->weight('bold'),
                TextColumn::make('variacion')
                    ->label('Variación')
                    ->state(fn (CambioPrecio $record): ?string => ($v = $record->variacionPrecio()) === null ? null : ($v > 0 ? '+' : '').number_format($v, 2).'%')
                    ->color(fn (CambioPrecio $record): string => ($record->variacionPrecio() ?? 0) > 0 ? 'danger' : 'success')
                    ->placeholder('—'),
                TextColumn::make('usuario.name')
                    ->label('Quién')
                    ->placeholder('Sistema')
                    ->searchable(),
                TextColumn::make('bodega.nombre')
                    ->label('Desde')
                    ->placeholder('—'),
                TextColumn::make('nota')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('proveedor')
                    ->label('Proveedor')
                    ->options(fn (): array => Proveedor::query()->whereHas('productos')->orderBy('nombre')->get()->mapWithKeys(fn (Proveedor $p): array => [$p->id => $p->etiqueta()])->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, mixed $proveedor): Builder => $q->whereHas('producto', fn (Builder $p): Builder => $p->where('proveedor_id', $proveedor)),
                    )),
                SelectFilter::make('motivo')
                    ->options(MotivoCambioPrecio::class),
                SelectFilter::make('user_id')
                    ->label('Usuario')
                    ->relationship('usuario', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('bodega_id')
                    ->label('Bodega')
                    ->relationship('bodega', 'nombre'),
                Filter::make('fechas')
                    ->schema([
                        DatePicker::make('desde'),
                        DatePicker::make('hasta'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['desde'] ?? null, fn (Builder $q, mixed $fecha): Builder => $q->whereDate('created_at', '>=', $fecha))
                        ->when($data['hasta'] ?? null, fn (Builder $q, mixed $fecha): Builder => $q->whereDate('created_at', '<=', $fecha))),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCambiosPrecio::route('/'),
        ];
    }
}
