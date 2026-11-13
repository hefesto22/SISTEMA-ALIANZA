<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos;

use App\Filament\Resources\Productos\Pages\CreateProducto;
use App\Filament\Resources\Productos\Pages\EditProducto;
use App\Filament\Resources\Productos\Pages\ListProductos;
use App\Filament\Resources\Productos\Pages\ViewProducto;
use App\Filament\Resources\Productos\RelationManagers\CambiosPrecioRelationManager;
use App\Filament\Resources\Productos\RelationManagers\MovimientosRelationManager;
use App\Filament\Resources\Productos\Schemas\ProductoForm;
use App\Filament\Resources\Productos\Schemas\ProductoInfolist;
use App\Filament\Resources\Productos\Tables\ProductosTable;
use App\Filament\Support\Acceso;
use App\Models\Existencia;
use App\Models\Producto;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductoResource extends Resource
{
    protected static ?string $model = Producto::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $recordTitleAttribute = 'descripcion';

    protected static ?string $modelLabel = 'Producto';

    protected static ?string $pluralModelLabel = 'Productos y precios';

    protected static ?string $navigationLabel = 'Productos y precios';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Precios';
    }

    /**
     * Los costos y márgenes solo los ve quien puede cambiar precios
     * (administración y bodega); el vendedor solo ve precios de venta.
     */
    public static function puedeVerCostos(): bool
    {
        return Acceso::puede('Update:Producto');
    }

    public static function form(Schema $schema): Schema
    {
        return ProductoForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProductoInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductosTable::configure($table);
    }

    /**
     * Con la existencia: la de su bodega (encargado y vendedor) o la de
     * todas las bodegas juntas (administración). "existencia" es el total
     * en unidades base (para ordenar) y las existencias cargadas dicen
     * cuánto hay de cada presentación.
     */
    public static function getEloquentQuery(): Builder
    {
        $usuario = Acceso::usuario();
        $todas = $usuario === null || $usuario->veTodasLasBodegas();
        $bodegaId = $usuario?->bodega_id;
        $total = Existencia::query()
            ->join('presentaciones as pe', 'pe.id', '=', 'existencias.presentacion_id')
            ->selectRaw('COALESCE(SUM(existencias.cantidad * pe.contenido), 0)')
            ->whereColumn('existencias.producto_id', 'productos.id')
            ->when(! $todas, fn (Builder $query): Builder => $query->where('existencias.bodega_id', $bodegaId));

        return parent::getEloquentQuery()
            ->with([
                'proveedor',
                'categoria',
                'presentaciones.unidadMedida',
                'unidadMedida',
                'existencias' => fn (HasMany $existencias): HasMany => $todas ? $existencias : $existencias->where('bodega_id', $bodegaId),
            ])
            ->addSelect(['existencia' => $total]);
    }

    public static function getRelations(): array
    {
        return [
            CambiosPrecioRelationManager::class,
            MovimientosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListProductos::route('/'),
            'create' => CreateProducto::route('/create'),
            'view'   => ViewProducto::route('/{record}'),
            'edit'   => EditProducto::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['codigo', 'descripcion'];
    }
}
