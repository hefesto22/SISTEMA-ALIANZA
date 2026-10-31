<?php

declare(strict_types=1);

namespace App\Filament\Resources\Rutas\Pages;

use App\Filament\Resources\Rutas\RutaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;

class ManageRutas extends ManageRecords
{
    protected static string $resource = RutaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->modalWidth(Width::FourExtraLarge)
                ->mutateDataUsing(fn (array $data): array => RutaResource::completar($data)),
        ];
    }
}
