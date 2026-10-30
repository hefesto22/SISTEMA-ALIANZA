<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras;

use App\Filament\Resources\Compras\Pages\CreateCompra;
use App\Filament\Resources\Compras\Pages\ListCompras;
use App\Filament\Resources\Compras\Pages\ViewCompra;
use App\Filament\Resources\Compras\Schemas\CompraForm;
use App\Filament\Resources\Compras\Schemas\CompraInfolist;
use App\Filament\Resources\Compras\Tables\ComprasTable;
use App\Filament\Support\Acceso;
use App\Models\Compra;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Compras a proveedores: lo que entra a cada bodega. Al registrar una compra
 * sube la existencia de esa bodega y se actualizan costo y precio de venta
 * (RegistrarCompra). No se editan: lo que entró mal se corrige con un ajuste.
 *
 * @extends \Filament\Resources\Resource<Compra>
 */
class CompraResource extends Resource
{
    protected static ?string $model = Compra::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $recordTitleAttribute = 'numero';

    protected static ?string $modelLabel = 'Compra';

    protected static ?string $pluralModelLabel = 'Compras';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Inventario';
    }

    public static function form(Schema $schema): Schema
    {
        return CompraForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CompraInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ComprasTable::configure($table);
    }

    /**
     * Administración ve todas; el encargado, las de su bodega.
     */
    public static function getEloquentQuery(): Builder
    {
        $usuario = Acceso::usuario();
        $query = Compra::query()->with(['bodega', 'proveedor', 'usuario']);

        return $usuario === null ? $query->whereRaw('1 = 0') : $query->visiblePara($usuario);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListCompras::route('/'),
            'create' => CreateCompra::route('/create'),
            'view'   => ViewCompra::route('/{record}'),
        ];
    }
}
