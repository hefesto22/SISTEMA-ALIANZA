<?php

declare(strict_types=1);

namespace App\Filament\Resources\Devoluciones\Pages;

use App\Filament\Pages\ReporteDevoluciones;
use App\Filament\Resources\Devoluciones\DevolucionResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

/**
 * Historial de devoluciones. Arriba, el reporte de devoluciones y pérdidas
 * (lo dañado, lo de cambio por cambio y lo que se devolvió en crédito).
 */
class ListDevoluciones extends ListRecords
{
    protected static string $resource = DevolucionResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('reporte')
                ->label('Reporte de devoluciones y pérdidas')
                ->icon(Heroicon::OutlinedReceiptRefund)
                ->color('gray')
                ->url(fn (): string => ReporteDevoluciones::getUrl())
                ->visible(fn (): bool => ReporteDevoluciones::canAccess()),
        ];
    }
}
