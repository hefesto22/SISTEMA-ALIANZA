<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Pages;

use App\Filament\Resources\Productos\ProductoResource;
use App\Models\Proveedor;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lista de precios con una pestaña por proveedor (cada uno por separado).
 */
class ListProductos extends ListRecords
{
    protected static string $resource = ProductoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo producto'),
        ];
    }

    public function getSubheading(): string
    {
        return 'Precios sin ISV por unidad y por caja. Son los mismos en todas las bodegas.';
    }

    /**
     * @return array<string|int, Tab>
     */
    public function getTabs(): array
    {
        $tabs = ['todos' => Tab::make('Todos')];

        $proveedores = Proveedor::query()
            ->whereHas('productos')
            ->withCount('productos')
            ->orderBy('nombre')
            ->get();

        foreach ($proveedores as $proveedor) {
            $tabs['p'.$proveedor->id] = Tab::make($proveedor->etiqueta())
                ->badge((string) $proveedor->productos_count)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('proveedor_id', $proveedor->id));
        }

        return $tabs;
    }
}
