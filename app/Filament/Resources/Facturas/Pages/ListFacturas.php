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

    /**
     * "Hoy: 3 facturas por L. 1,250.00 · 1 anulada: retire esa hoja…"
     */
    public function getSubheading(): string
    {
        $hoy = FacturaResource::getEloquentQuery()
            ->where('estado', EstadoFactura::Vigente)
            ->whereDate('fecha_emision', today());
        $cuantas = (clone $hoy)->count();
        $monto = (float) (clone $hoy)->sum('total');
        $anuladas = FacturaResource::getEloquentQuery()->where('estado', EstadoFactura::Anulada)->count();

        $partes = [$cuantas === 0
            ? 'Hoy no se ha emitido ninguna factura'
            : 'Hoy: '.($cuantas === 1 ? '1 factura' : "{$cuantas} facturas").' por '.lempiras($monto)];

        if ($anuladas > 0) {
            $partes[] = $anuladas === 1
                ? '1 anulada: retire esa hoja al cliente y guárdela marcada ANULADA'
                : "{$anuladas} anuladas: retire esas hojas al cliente y guárdelas marcadas ANULADA";
        }

        return implode(' · ', $partes).'.';
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
