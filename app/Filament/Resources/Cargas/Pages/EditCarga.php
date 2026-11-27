<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cargas\Pages;

use App\Domain\Cargas\ArmarCarga;
use App\Domain\Exceptions\ReglaNegocioException;
use App\Filament\Resources\Cargas\CargaResource;
use App\Filament\Resources\Cargas\Schemas\CargaForm;
use App\Filament\Support\Acceso;
use App\Models\Carga;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;

/**
 * Cambiar lo que lleva una carga mientras va en ruta (quién sale, el camión,
 * las facturas). La bodega no cambia.
 */
class EditCarga extends EditRecord
{
    protected static string $resource = CargaResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->modalDescription('La carga se borra: sus facturas quedan libres para otra carga (no se anulan) y los cambios que llevaba regresan a la bodega, por entregar.')
                ->using(function (Carga $record): bool {
                    app(ArmarCarga::class)->borrar($record, Acceso::usuario() ?? abort(403));

                    return true;
                }),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $carga = $this->getRecord();

        return $carga instanceof Carga ? CargaForm::llenar($carga) : $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $usuario = Acceso::usuario() ?? abort(403);

        if (! $record instanceof Carga) {
            abort(404);
        }

        try {
            return CargaForm::armar($record, $data, $usuario);
        } catch (ReglaNegocioException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return CargaResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
