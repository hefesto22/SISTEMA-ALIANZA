<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlanesPromocion\Pages;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Promociones\GuardarPlanPromocion;
use App\Filament\Resources\PlanesPromocion\PlanPromocionResource;
use App\Filament\Resources\PlanesPromocion\Schemas\PlanPromocionForm;
use App\Filament\Support\Acceso;
use App\Models\PlanPromocion;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;

class EditPlanPromocion extends EditRecord
{
    protected Width|string|null $maxContentWidth = Width::Full;

    protected static string $resource = PlanPromocionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('Ver avance'),
            DeleteAction::make(),
        ];
    }

    /**
     * Sus bodegas y una fila por cada línea (promoción) del plan.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $plan = $this->getRecord();

        return $plan instanceof PlanPromocion ? PlanPromocionForm::llenar($plan, $data) : $data;
    }

    /**
     * Lo ya regalado de cada línea sigue contando para su tope.
     *
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $usuario = Acceso::usuario() ?? abort(403);

        if (! $record instanceof PlanPromocion) {
            abort(404);
        }

        try {
            return app(GuardarPlanPromocion::class)->ejecutar($record, PlanPromocionForm::datos($data), $usuario);
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
