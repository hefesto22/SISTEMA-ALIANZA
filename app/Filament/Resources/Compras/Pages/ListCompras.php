<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras\Pages;

use App\Filament\Pages\ReporteCompras;
use App\Filament\Resources\Compras\CompraResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListCompras extends ListRecords
{
    protected static string $resource = CompraResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reporte')
                ->label('Reporte por proveedor')
                ->icon(Heroicon::OutlinedPresentationChartBar)
                ->color('gray')
                ->url(fn (): string => ReporteCompras::getUrl())
                ->visible(fn (): bool => ReporteCompras::canAccess()),
            CreateAction::make()->label('Registrar compra'),
        ];
    }
}
