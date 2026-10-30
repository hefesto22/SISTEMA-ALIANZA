<?php

declare(strict_types=1);

namespace App\Filament\Resources\Facturas\Pages;

use App\Enums\EstadoFactura;
use App\Filament\Resources\Facturas\FacturaResource;
use App\Filament\Resources\Pedidos\Actions\ImprimirPedidoAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;

class ListFacturas extends ListRecords
{
    protected static string $resource = FacturaResource::class;

    /**
     * Botón "No salió" del aviso después de imprimir una copia.
     *
     * @param array<int|string, mixed> $ids
     */
    #[On(ImprimirPedidoAction::PEDIR)]
    public function imprimirPedidos(array $ids = [], string $modo = 'originales'): void
    {
        ImprimirPedidoAction::desdeAviso($this, $ids, $modo);
    }

    public function getSubheading(): string
    {
        $anuladas = FacturaResource::getEloquentQuery()->where('estado', EstadoFactura::Anulada)->count();

        return $anuladas === 0
            ? 'Sin facturas anuladas.'
            : "{$anuladas} anulada(s): retire esas hojas al cliente y guárdelas marcadas ANULADA.";
    }

    /**
     * @return array<string|int, Tab>
     */
    public function getTabs(): array
    {
        $anuladas = FacturaResource::getEloquentQuery()->where('estado', EstadoFactura::Anulada)->count();

        return [
            'vigentes' => Tab::make('Vigentes')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('estado', EstadoFactura::Vigente)),
            'anuladas' => Tab::make('Anuladas')
                ->badge($anuladas > 0 ? (string) $anuladas : null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('estado', EstadoFactura::Anulada)),
            'todas' => Tab::make('Todas'),
        ];
    }
}
