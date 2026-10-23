<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Pages;

use App\Domain\Pedidos\RecalcularPedido;
use App\Filament\Resources\Pedidos\PedidoResource;
use App\Filament\Resources\Pedidos\Schemas\PedidoForm;
use App\Filament\Support\Acceso;
use App\Models\Pedido;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditPedido extends EditRecord
{
    protected static string $resource = PedidoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $usuario = Acceso::usuario();

        // Solo administración puede mover un pedido de bodega o de vendedor.
        if ($usuario !== null && ! $usuario->veTodasLasBodegas()) {
            unset($data['bodega_id']);

            if ($usuario->esVendedor()) {
                unset($data['vendedor_id']);
                $data = PedidoForm::marcarFueraDeRuta($data, $usuario);
            }
        }

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var Pedido $pedido */
        $pedido = $this->getRecord();

        app(RecalcularPedido::class)->ejecutar($pedido, preciosVigentes: true);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
