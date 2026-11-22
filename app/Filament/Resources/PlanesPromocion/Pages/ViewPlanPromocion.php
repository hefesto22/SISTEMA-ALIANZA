<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlanesPromocion\Pages;

use App\Filament\Resources\PlanesPromocion\PlanPromocionResource;
use App\Models\PlanPromocion;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;

class ViewPlanPromocion extends ViewRecord
{
    protected static string $resource = PlanPromocionResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    public function getTitle(): string
    {
        $plan = $this->getRecord();

        return $plan instanceof PlanPromocion ? $plan->nombre : 'Plan del proveedor';
    }

    public function getSubheading(): ?string
    {
        $plan = $this->getRecord();

        return $plan instanceof PlanPromocion ? $plan->proveedor->nombre.' · '.$plan->vigenciaTexto().' · '.$plan->bodegasTexto() : null;
    }
}
