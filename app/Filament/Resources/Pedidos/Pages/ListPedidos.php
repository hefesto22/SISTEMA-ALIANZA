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

    /**
     * "Todas las bodegas · 6 pendientes · L. 4,250.00 por despachar · 1 fuera de ruta".
     */
    public function getSubheading(): string
    {
        $pendientes = PedidoResource::getEloquentQuery()->where('estado', EstadoPedido::Pendiente);

        $cuantos = (clone $pendientes)->count();
        $monto = (float) (clone $pendientes)->sum('total');
        $fuera = (clone $pendientes)->where('fuera_de_ruta', true)->count();

        $partes = [
            match (true) {
                Acceso::esVendedor() => 'Sus pedidos',
                Acceso::veTodo()     => 'Todas las bodegas',
                default              => 'Vendedores de su bodega',
            },
            $cuantos.' '.($cuantos === 1 ? 'pendiente' : 'pendientes'),
            lempiras($monto).' por despachar',
        ];

        if ($fuera > 0) {
            $partes[] = "{$fuera} fuera de ruta";
        }

        return implode(' · ', $partes);
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
