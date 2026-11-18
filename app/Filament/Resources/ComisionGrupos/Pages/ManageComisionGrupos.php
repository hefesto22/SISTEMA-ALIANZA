<?php

declare(strict_types=1);

namespace App\Filament\Resources\ComisionGrupos\Pages;

use App\Filament\Resources\ComisionGrupos\ComisionGrupoResource;
use App\Models\ComisionGrupo;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;

class ManageComisionGrupos extends ManageRecords
{
    protected static string $resource = ComisionGrupoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nuevo grupo')
                ->modalHeading('Nuevo grupo de comisión')
                ->modalWidth(Width::FourExtraLarge)
                ->using(fn (array $data): ComisionGrupo => ComisionGrupoResource::guardar(null, $data)),
        ];
    }
}
