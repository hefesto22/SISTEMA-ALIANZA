<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Pages;

use App\Domain\Pedidos\RecalcularPedido;
use App\Filament\Resources\Pedidos\PedidoResource;
use App\Filament\Resources\Pedidos\Schemas\PedidoForm;
use App\Filament\Support\Acceso;
use App\Models\Pedido;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPedido extends EditRecord
{
    protected static string $resource = PedidoResource::class;

    /**
     * Guardar va en una transacción con el pedido bloqueado (beforeValidate):
     * si bodega lo está validando, uno espera al otro.
     */
    protected ?bool $hasDatabaseTransactions = true;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * Antes de guardar, el pedido queda bloqueado y se revisa que siga
     * pendiente: si bodega lo validó mientras se editaba, no se guarda (si
     * no, una línea nueva se cobraría sin que bodega la haya despachado).
     */
    protected function beforeValidate(): void
    {
        $actual = Pedido::query()->lockForUpdate()->find($this->getRecord()->getKey());

        if ($actual === null || ! $actual->estaPendiente()) {
            Notification::make()
                ->title('Este pedido ya no se puede editar')
                ->body('Bodega ya lo validó'.($actual !== null ? " (está {$actual->estado->getLabel()})" : '').'. Si hay que cambiar algo, bodega lo corrige.')
                ->danger()
                ->persistent()
                ->send();

            $this->halt(shouldRollbackDatabaseTransaction: true);
        }
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

    /**
     * "Guardar cambios" va en la barra de abajo con el total (PedidoForm).
     *
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getCancelFormAction(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
