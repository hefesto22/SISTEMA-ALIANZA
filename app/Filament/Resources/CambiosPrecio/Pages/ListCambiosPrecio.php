<?php

declare(strict_types=1);

namespace App\Filament\Resources\CambiosPrecio\Pages;

use App\Filament\Resources\CambiosPrecio\CambioPrecioResource;
use Filament\Resources\Pages\ListRecords;

class ListCambiosPrecio extends ListRecords
{
    protected static string $resource = CambioPrecioResource::class;
}
