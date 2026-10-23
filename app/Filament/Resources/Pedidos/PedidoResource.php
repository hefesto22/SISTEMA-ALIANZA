<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos;

use App\Enums\EstadoPedido;
use App\Filament\Resources\Pedidos\Pages\CreatePedido;
use App\Filament\Resources\Pedidos\Pages\EditPedido;
use App\Filament\Resources\Pedidos\Pages\ListPedidos;
use App\Filament\Resources\Pedidos\Pages\ViewPedido;
use App\Filament\Resources\Pedidos\Schemas\PedidoForm;
use App\Filament\Resources\Pedidos\Schemas\PedidoInfolist;
use App\Filament\Resources\Pedidos\Tables\PedidosTable;
use App\Filament\Support\Acceso;
use App\Models\Pedido;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends \Filament\Resources\Resource<Pedido>
 */
class PedidoResource extends Resource
{
    protected static ?string $model = Pedido::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $recordTitleAttribute = 'numero';

    protected static ?string $modelLabel = 'Pedido';

    protected static ?string $pluralModelLabel = 'Pedidos';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Ventas';
    }

    public static function form(Schema $schema): Schema
    {
        return PedidoForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PedidoInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PedidosTable::configure($table);
    }

    /**
     * Vendedor: solo sus pedidos. Bodega: los de su bodega. Administración: todos.
     */
    public static function getEloquentQuery(): Builder
    {
        $usuario = Acceso::usuario();
        $query = Pedido::query()->with(['cliente.municipio.departamento', 'vendedor', 'bodega'])->withCount('items');

        return $usuario === null ? $query->whereRaw('1 = 0') : $query->visiblePara($usuario);
    }

    /**
     * Cuántos pedidos esperan validación (de los que este usuario ve).
     */
    public static function getNavigationBadge(): ?string
    {
        $pendientes = static::getEloquentQuery()->where('estado', EstadoPedido::Pendiente)->count();

        return $pendientes > 0 ? (string) $pendientes : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListPedidos::route('/'),
            'create' => CreatePedido::route('/create'),
            'view'   => ViewPedido::route('/{record}'),
            'edit'   => EditPedido::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['numero', 'cliente.nombre'];
    }
}
