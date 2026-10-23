<?php

declare(strict_types=1);

namespace App\Filament\Resources\Facturas\Pages;

use App\Enums\EstadoFactura;
use App\Filament\Resources\Facturas\FacturaResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListFacturas extends ListRecords
{
    protected static string $resource = FacturaResource::class;

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
