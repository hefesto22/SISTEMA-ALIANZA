<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Pages;

use App\Enums\EstadoPedido;
use App\Filament\Resources\Pedidos\PedidoResource;
use App\Filament\Support\Acceso;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPedidos extends ListRecords
{
    protected static string $resource = PedidoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva preventa'),
        ];
    }

    public function getSubheading(): string
    {
        if (Acceso::esVendedor()) {
            return 'Sus pedidos de preventa.';
        }

        return Acceso::veTodo()
            ? 'Pedidos de todas las bodegas.'
            : 'Pedidos de su bodega: valide el despacho de los pendientes.';
    }

    /**
     * @return array<string|int, Tab>
     */
    public function getTabs(): array
    {
        $tabs = [];

        foreach (EstadoPedido::cases() as $estado) {
            $cantidad = PedidoResource::getEloquentQuery()->where('estado', $estado)->count();

            $tabs[$estado->value] = Tab::make($estado->getLabel())
                ->badge($cantidad > 0 ? (string) $cantidad : null)
                ->badgeColor($estado->getColor())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('estado', $estado));
        }

        $tabs['todos'] = Tab::make('Todos');

        return $tabs;
    }
}
