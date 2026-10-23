<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Actions;

use App\Models\Pedido;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

final class ImprimirPedidoAction
{
    public static function make(): Action
    {
        return Action::make('imprimir')
            ->label('Imprimir')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->authorize('view')
            ->url(fn (Pedido $record): string => route('pedidos.imprimir', $record))
            ->openUrlInNewTab();
    }
}
