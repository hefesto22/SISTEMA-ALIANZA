<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras\Pages;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Inventario\RegistrarCompra;
use App\Filament\Resources\Compras\CompraResource;
use App\Filament\Resources\Compras\Schemas\CompraForm;
use App\Filament\Support\Acceso;
use App\Models\Bodega;
use App\Models\Proveedor;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class CreateCompra extends CreateRecord
{
    protected static string $resource = CompraResource::class;

    protected static ?string $title = 'Registrar compra';

    protected static bool $canCreateAnother = false;

    /**
     * La compra entra por RegistrarCompra: existencia de la bodega, costo,
     * precio de venta e historial, todo junto. El encargado registra solo
     * para su bodega.
     *
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $usuario = Acceso::usuario() ?? abort(403);

        $bodegaId = $usuario->veTodasLasBodegas() ? $data['bodega_id'] ?? null : $usuario->bodega_id;
        $bodega = is_numeric($bodegaId) ? Bodega::query()->find((int) $bodegaId) : null;

        if (! $bodega instanceof Bodega) {
            Notification::make()
                ->title('Escoja la bodega a la que entra la compra')
                ->body('Si es encargado, pida a administración que le asigne su bodega en Usuarios.')
                ->danger()
                ->send();

            $this->halt();
        }

        try {
            return app(RegistrarCompra::class)->ejecutar(
                $bodega,
                Proveedor::query()->findOrFail($data['proveedor_id'] ?? null),
                CompraForm::lineas($data),
                $usuario,
                is_string($data['factura_proveedor'] ?? null) ? $data['factura_proveedor'] : null,
                filled($data['fecha'] ?? null) ? Carbon::parse((string) $data['fecha']) : null,
                is_string($data['notas'] ?? null) ? $data['notas'] : null,
            );
        } catch (ReglaNegocioException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            $this->halt();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Compra registrada: subió la existencia y se actualizaron costos y precios';
    }

    protected function getRedirectUrl(): string
    {
        return CompraResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
