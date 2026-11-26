<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cargas\Pages;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Reportes\Periodo;
use App\Filament\Resources\Cargas\CargaResource;
use App\Filament\Resources\Cargas\Schemas\CargaForm;
use App\Filament\Support\Acceso;
use App\Models\Carga;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;

/**
 * Armar una carga. Desde Pedidos → Impresos ("Cargar camión" de un día)
 * llega con ?dia=2026-11-26&bodega=2: la bodega y las facturas de ese día
 * que todavía no van en otra carga vienen marcadas.
 */
class CreateCarga extends CreateRecord
{
    protected static string $resource = CargaResource::class;

    protected static ?string $title = 'Nueva carga';

    protected static bool $canCreateAnother = false;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function fillForm(): void
    {
        $usuario = Acceso::usuario();
        $pedida = request()->query('bodega');
        $bodega = $usuario?->veTodasLasBodegas() ? (is_numeric($pedida) ? (int) $pedida : null) : $usuario?->bodega_id;
        $dia = Periodo::dia(request()->query('dia'));

        $this->callHook('beforeFill');

        $this->form->fill([
            'bodega_id' => $bodega,
            'fecha'     => today()->toDateString(),
            'facturas'  => $bodega !== null && $dia !== null ? CargaForm::delDia($bodega, $dia) : [],
        ]);

        $this->callHook('afterFill');
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $usuario = Acceso::usuario() ?? abort(403);

        try {
            return CargaForm::armar(null, $data, $usuario);
        } catch (ReglaNegocioException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            $this->halt();
        }
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Guardar carga');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        $carga = $this->getRecord();

        return $carga instanceof Carga
            ? "Carga {$carga->numero()} lista: sale con ".lempiras($carga->total_carga)
            : 'Carga lista';
    }

    protected function getRedirectUrl(): string
    {
        return CargaResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
