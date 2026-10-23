<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Pages;

use App\Filament\Resources\Pedidos\Actions\AnularPedidoAction;
use App\Filament\Resources\Pedidos\Actions\ImprimirPedidoAction;
use App\Filament\Resources\Pedidos\Actions\ValidarDespachoAction;
use App\Filament\Resources\Pedidos\PedidoResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPedido extends ViewRecord
{
    protected static string $resource = PedidoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ValidarDespachoAction::make(),
            EditAction::make(),
            ImprimirPedidoAction::make(),
            AnularPedidoAction::make(),
        ];
    }
}
