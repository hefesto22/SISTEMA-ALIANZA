<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlanesPromocion\Pages;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Promociones\GuardarPlanPromocion;
use App\Filament\Resources\PlanesPromocion\PlanPromocionResource;
use App\Filament\Resources\PlanesPromocion\Schemas\PlanPromocionForm;
use App\Filament\Support\Acceso;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;

class CreatePlanPromocion extends CreateRecord
{
    protected static string $resource = PlanPromocionResource::class;

    protected static ?string $title = 'Nuevo plan del proveedor';

    protected static bool $canCreateAnother = false;

    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * El plan y sus líneas entran por GuardarPlanPromocion (cada línea, una promoción).
     *
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $usuario = Acceso::usuario() ?? abort(403);

        try {
            return app(GuardarPlanPromocion::class)->ejecutar(null, PlanPromocionForm::datos($data), $usuario);
        } catch (ReglaNegocioException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
