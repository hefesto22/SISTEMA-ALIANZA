<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Pages;

use App\Domain\Pedidos\RecalcularPedido;
use App\Filament\Resources\Pedidos\PedidoResource;
use App\Filament\Resources\Pedidos\Schemas\PedidoForm;
use App\Filament\Support\Acceso;
use App\Models\Pedido;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreatePedido extends CreateRecord
{
    protected static string $resource = PedidoResource::class;

    protected static ?string $title = 'Nueva preventa';

    /**
     * El vendedor no escoge vendedor ni bodega: son los suyos. Si el cliente
     * no es de sus rutas, el pedido queda marcado fuera de ruta.
     * Bodega crea pedidos solo para su bodega.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $usuario = Acceso::usuario();

        if ($usuario !== null && ! $usuario->veTodasLasBodegas()) {
            $data['bodega_id'] = $usuario->bodega_id;

            if ($usuario->esVendedor()) {
                $data['vendedor_id'] = $usuario->id;
                $data = PedidoForm::marcarFueraDeRuta($data, $usuario);
            }
        }

        if (blank($data['bodega_id'] ?? null)) {
            Notification::make()
                ->title('Su usuario no tiene bodega asignada')
                ->body('Pida a administración que le asigne una bodega en Usuarios.')
                ->danger()
                ->send();

            $this->halt();
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Pedido $pedido */
        $pedido = $this->getRecord();

        app(RecalcularPedido::class)->ejecutar($pedido, preciosVigentes: true);
    }

    /**
     * "Guardar pedido" va en la barra de abajo con el total (PedidoForm); aquí
     * quedan las demás.
     *
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getCreateAnotherFormAction()->label('Guardar y hacer otro'),
            $this->getCancelFormAction(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
