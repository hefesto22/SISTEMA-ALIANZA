<?php

declare(strict_types=1);

namespace App\Filament\Resources\ComisionMetas\Pages;

use App\Filament\Resources\ComisionMetas\ComisionMetaResource;
use App\Models\ComisionMeta;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;

class ManageComisionMetas extends ManageRecords
{
    protected static string $resource = ComisionMetaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nueva meta')
                ->modalHeading('Nueva meta de vendedor')
                ->modalWidth(Width::ThreeExtraLarge)
                ->using(fn (array $data): ComisionMeta => ComisionMetaResource::guardar(null, $data)),
        ];
    }
}
