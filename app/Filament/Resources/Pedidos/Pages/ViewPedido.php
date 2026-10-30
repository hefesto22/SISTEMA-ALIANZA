<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Pages;

use App\Filament\Resources\Pedidos\Actions\AnularPedidoAction;
use App\Filament\Resources\Pedidos\Actions\CorregirPedidoAction;
use App\Filament\Resources\Pedidos\Actions\ImprimirPedidoAction;
use App\Filament\Resources\Pedidos\Actions\ValidarDespachoAction;
use App\Filament\Resources\Pedidos\PedidoResource;
use App\Models\Pedido;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\On;

class ViewPedido extends ViewRecord
{
    protected static string $resource = PedidoResource::class;

    /**
     * "Pedido COP-000021" en vez de "Ver COP-000021".
     */
    public function getTitle(): string
    {
        $pedido = $this->getRecord();

        return 'Pedido '.($pedido instanceof Pedido ? $pedido->numero : '');
    }

    protected function getHeaderActions(): array
    {
        return [
            ValidarDespachoAction::make(),
            CorregirPedidoAction::make(),
            ImprimirPedidoAction::make(),
            ImprimirPedidoAction::copia(),
            EditAction::make(),
            AnularPedidoAction::make(),
            ActionGroup::make([
                ImprimirPedidoAction::vistaPrevia(),
                ImprimirPedidoAction::noSalio(),
            ])
                ->icon(Heroicon::EllipsisHorizontal)
                ->color('gray')
                ->tooltip('Más'),
        ];
    }

    /**
     * Botones de los avisos ("Imprimir" al validar o corregir, "No salió").
     *
     * @param array<int|string, mixed> $ids
     */
    #[On(ImprimirPedidoAction::PEDIR)]
    public function imprimirPedidos(array $ids = [], string $modo = 'originales'): void
    {
        ImprimirPedidoAction::desdeAviso($this, $ids, $modo);
        $this->getRecord()->refresh();
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
