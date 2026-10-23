<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Pages;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Precios\ActualizarPrecioProducto;
use App\Domain\Precios\CalculadoraPrecio;
use App\Enums\MotivoCambioPrecio;
use App\Filament\Resources\Productos\ProductoResource;
use App\Models\Producto;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateProducto extends CreateRecord
{
    protected static string $resource = ProductoResource::class;

    /**
     * Con el producto ya creado, se carga su primer costo/precio por la
     * misma puerta de siempre para que el historial arranque desde aquí.
     */
    protected function afterCreate(): void
    {
        /** @var Producto $producto */
        $producto = $this->getRecord();
        $producto->load('categoria');

        /** @var User|null $usuario */
        $usuario = Auth::user();
        $datos = $this->data ?? [];

        try {
            $costoUnidad = CalculadoraPrecio::costoUnitario(
                costo: (float) ($datos['costo_inicial'] ?? 0),
                porCaja: ($datos['costo_por'] ?? 'unidad') === 'caja',
                unidadesPorCaja: max(1, $producto->unidades_por_caja),
            );

            app(ActualizarPrecioProducto::class)->ejecutar($producto, $costoUnidad, MotivoCambioPrecio::Compra, $usuario, nota: 'Producto nuevo');
        } catch (ReglaNegocioException $e) {
            Notification::make()->title('El producto se creó, pero falta su precio')->body($e->getMessage())->warning()->send();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
