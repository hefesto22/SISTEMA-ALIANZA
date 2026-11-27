<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cargas\Pages;

use App\Enums\EstadoCarga;
use App\Enums\EstadoReposicion;
use App\Filament\Resources\Cargas\CargaResource;
use App\Filament\Support\Acceso;
use App\Models\Reposicion;
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

    /**
     * Y, si hay, cuántos cambios esperan carga (de la bodega del usuario).
     */
    public function getSubheading(): string
    {
        $texto = 'Lo que sale en cada camión y, al regresar, cuánto debía traer y cuánto trajo.';
        $usuario = Acceso::usuario();

        $porEntregar = Reposicion::query()
            ->where('estado', EstadoReposicion::PorEntregar->value)
            ->when($usuario !== null && ! $usuario->veTodasLasBodegas(), fn (Builder $q): Builder => $q->where('bodega_id', $usuario?->bodega_id ?? 0))
            ->count();

        if ($porEntregar === 0) {
            return $texto;
        }

        return $texto.' '.($porEntregar === 1 ? 'Hay 1 cambio por entregar' : "Hay {$porEntregar} cambios por entregar").': se escogen al armar la carga.';
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
