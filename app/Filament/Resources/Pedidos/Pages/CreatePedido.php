<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pedidos\Pages;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Pedidos\RecalcularPedido;
use App\Domain\Pedidos\VentaEnBodega;
use App\Filament\Resources\Pedidos\Actions\ImprimirPedidoAction;
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
     * Bodega crea pedidos solo para su bodega. Si el vendedor escogido es un
     * usuario de bodega, es venta en bodega: el descuento no pasa de su tope
     * (VentaEnBodega::datos).
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

        $data = VentaEnBodega::datos($data);

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

    /**
     * Se calcula con los precios de hoy. La venta en bodega queda validada de
     * una vez (sale de la bodega) y lista para imprimir.
     */
    protected function afterCreate(): void
    {
        /** @var Pedido $pedido */
        $pedido = $this->getRecord();

        app(RecalcularPedido::class)->ejecutar($pedido, preciosVigentes: true);

        if ($pedido->en_bodega) {
            self::validarVentaEnBodega($pedido);
        }
    }

    /**
     * Valida la venta en bodega y avisa; si no se puede (algo no alcanza),
     * queda pendiente para validarla a mano.
     */
    public static function validarVentaEnBodega(Pedido $pedido): void
    {
        $usuario = Acceso::usuario();

        if ($usuario === null) {
            return;
        }

        try {
            $pedido = app(VentaEnBodega::class)->validar($pedido, $usuario);
        } catch (ReglaNegocioException $e) {
            Notification::make()
                ->title("Venta {$pedido->numero} guardada, pero no se pudo validar")
                ->body($e->getMessage().' Quedó pendiente: valídela desde el pedido.')
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title("Venta {$pedido->numero} validada: lista para imprimir")
            ->body('Total para el cliente: '.lempiras($pedido->total).($pedido->descuento_mayoreo > 0 ? ' (con '.lempiras($pedido->descuento_mayoreo).' de descuento).' : '.').($pedido->se_lo_lleva ? ' Se lo lleva: no va en camión.' : ' Va en camión: queda en Impreso para cargarlo.'))
            ->success()
            ->actions([ImprimirPedidoAction::enNotificacion($pedido)])
            ->send();
    }

    /**
     * La venta en bodega ya avisa sola (validada o no).
     */
    protected function getCreatedNotification(): ?Notification
    {
        $pedido = $this->getRecord();

        return $pedido instanceof Pedido && $pedido->en_bodega ? null : parent::getCreatedNotification();
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
