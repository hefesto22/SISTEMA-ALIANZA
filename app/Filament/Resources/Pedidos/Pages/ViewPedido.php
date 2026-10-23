<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Pages;

use App\Filament\Resources\Pedidos\Actions\AnularPedidoAction;
use App\Filament\Resources\Pedidos\Actions\CorregirPedidoAction;
use App\Filament\Resources\Pedidos\Actions\ImprimirPedidoAction;
use App\Filament\Resources\Pedidos\Actions\ValidarDespachoAction;
use App\Filament\Resources\Pedidos\PedidoResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\On;

class ViewPedido extends ViewRecord
{
    protected static string $resource = PedidoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ValidarDespachoAction::make(),
            CorregirPedidoAction::make(),
            ImprimirPedidoAction::make(),
            ImprimirPedidoAction::hoja(),
            EditAction::make(),
            AnularPedidoAction::make(),
        ];
    }

    /**
     * Después de imprimir: el pedido pasó a Impreso.
     */
    #[On('hoja-impresa')]
    public function hojaImpresa(): void
    {
        $this->getRecord()->refresh();
    }
}
