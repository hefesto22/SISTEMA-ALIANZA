<?php

declare(strict_types=1);

namespace App\Filament\Resources\Devoluciones\Pages;

use App\Filament\Resources\Devoluciones\DevolucionResource;
use Filament\Resources\Pages\ListRecords;

class ListDevoluciones extends ListRecords
{
    protected static string $resource = DevolucionResource::class;
}
