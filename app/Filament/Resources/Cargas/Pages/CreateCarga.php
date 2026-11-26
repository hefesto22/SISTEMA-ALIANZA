<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cargas\Pages;

use App\Domain\Cargas\FacturasParaCargar;
use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Reportes\Periodo;
use App\Filament\Resources\Cargas\CargaResource;
use App\Filament\Resources\Cargas\Schemas\CargaForm;
use App\Filament\Support\Acceso;
use App\Models\Bodega;
use App\Models\Carga;
use App\Models\Factura;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;

/**
 * Armar una carga. Desde Pedidos → Impresos ("Escoger una por una" al
 * cargar el camión con uno o varios días) llega con
 * ?dias=2026-11-25,2026-11-26&bodega=2 (o ?dia=2026-11-26): la bodega y las
 * facturas de esos días que todavía se pueden cargar vienen marcadas (si un
 * día es de antes, "Impresas desde" empieza en el más viejo).
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
        $dias = self::diasPedidos();

        // Administración: la primera bodega que tiene facturas sin cargar.
        if ($bodega === null && $usuario?->veTodasLasBodegas()) {
            $bodega = self::bodegaConFacturas();
        }

        // La lista empieza en los últimos días; si piden días de antes, en el
        // más viejo; si en los últimos días no hay, en el último día que quedó sin cargar.
        $desde = FacturasParaCargar::desdeDe()->toDateString();

        if ($dias !== [] && min($dias) < $desde) {
            $desde = min($dias);
        } elseif ($dias === [] && $bodega !== null && ! FacturasParaCargar::hayDesde($bodega, $desde)) {
            $desde = FacturasParaCargar::anteriores($bodega, $desde)['ultimo'] ?? $desde;
        }

        $this->callHook('beforeFill');

        $this->form->fill([
            'bodega_id' => $bodega,
            'fecha'     => today()->toDateString(),
            'desde'     => $desde,
            'facturas'  => $bodega !== null ? CargaForm::deLosDias($bodega, $dias)->map(fn (Factura $factura): string => (string) $factura->id)->values()->all() : [],
        ]);

        $this->callHook('afterFill');
    }

    /**
     * Los días que vienen en la dirección (?dias=…, o ?dia=… de un solo día), sin repetir y en orden.
     *
     * @return list<string>
     */
    private static function diasPedidos(): array
    {
        $texto = request()->query('dias', request()->query('dia'));
        $dias = [];

        foreach (explode(',', is_string($texto) ? $texto : '') as $valor) {
            $dia = Periodo::dia(trim($valor));

            if ($dia !== null) {
                $dias[$dia] = $dia;
            }
        }

        ksort($dias);

        return array_values($dias);
    }

    private static function bodegaConFacturas(): ?int
    {
        $bodegas = Bodega::query()->orderBy('nombre')->pluck('id')->map(fn (mixed $id): int => (int) $id);
        $desde = FacturasParaCargar::desdeDe()->subDays(FacturasParaCargar::ATRAS)->toDateString();

        return $bodegas->first(fn (int $id): bool => FacturasParaCargar::hayDesde($id, $desde))
            ?? ($bodegas->count() === 1 ? $bodegas->first() : null);
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
