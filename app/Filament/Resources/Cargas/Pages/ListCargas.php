<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cargas\Pages;

use App\Domain\Devoluciones\OrdenesDeCambio;
use App\Enums\EstadoCarga;
use App\Filament\Resources\Cargas\CargaResource;
use App\Filament\Support\Acceso;
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
     * Y, si hay, cuántas órdenes de cambio esperan carga (impresas) o
     * todavía no se imprimen (de la bodega del usuario).
     */
    public function getSubheading(): string
    {
        $texto = 'Lo que sale en cada camión y, al regresar, cuánto debía traer y cuánto trajo.';
        $usuario = Acceso::usuario();

        if ($usuario === null) {
            return $texto;
        }

        $porCargar = OrdenesDeCambio::de($usuario, OrdenesDeCambio::POR_CARGAR)->count();
        $porImprimir = OrdenesDeCambio::de($usuario, OrdenesDeCambio::POR_IMPRIMIR)->count();

        if ($porCargar > 0) {
            $texto .= ' '.($porCargar === 1 ? 'Hay 1 orden de cambio por cargar' : "Hay {$porCargar} órdenes de cambio por cargar").': se escogen al armar la carga.';
        }

        if ($porImprimir > 0) {
            $texto .= ' '.($porImprimir === 1 ? '1 orden de cambio falta' : "{$porImprimir} órdenes de cambio faltan").' de imprimir (Pedidos → Por imprimir).';
        }

        return $texto;
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
