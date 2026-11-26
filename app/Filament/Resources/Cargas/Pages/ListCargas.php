<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cargas\Pages;

use App\Enums\EstadoCarga;
use App\Filament\Resources\Cargas\CargaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCargas extends ListRecords
{
    protected static string $resource = CargaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva carga'),
        ];
    }

    public function getSubheading(): string
    {
        return 'Lo que sale en cada camión y, al regresar, cuánto debía traer y cuánto trajo.';
    }

    /**
     * En ruta (las que faltan por liquidar), liquidadas y todas.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $enRuta = CargaResource::getEloquentQuery()->where('estado', EstadoCarga::EnRuta)->count();

        return [
            'en_ruta' => Tab::make('En ruta')
                ->badge($enRuta > 0 ? (string) $enRuta : null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('estado', EstadoCarga::EnRuta)),
            'liquidadas' => Tab::make('Liquidadas')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('estado', EstadoCarga::Liquidada)),
            'todas' => Tab::make('Todas'),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return CargaResource::getEloquentQuery()->where('estado', EstadoCarga::EnRuta)->exists() ? 'en_ruta' : 'todas';
    }
}
