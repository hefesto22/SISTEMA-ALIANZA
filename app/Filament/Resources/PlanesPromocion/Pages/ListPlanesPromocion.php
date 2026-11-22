<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlanesPromocion\Pages;

use App\Filament\Resources\PlanesPromocion\PlanPromocionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlanesPromocion extends ListRecords
{
    protected static string $resource = PlanPromocionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo plan'),
        ];
    }

    public function getSubheading(): string
    {
        return 'El plan que manda cada proveedor para el mes, línea por línea, con las unidades de regalo que autoriza. La preventa no regala más de lo autorizado.';
    }
}
