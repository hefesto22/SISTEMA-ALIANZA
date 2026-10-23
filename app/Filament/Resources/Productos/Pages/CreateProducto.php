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

        // Costo y precio se escriben con ISV (si es gravado) y por caja o unidad; se guardan por unidad sin ISV.
        $unidades = ($datos['costo_por'] ?? 'unidad') === 'caja' ? max(1, $producto->unidades_por_caja) : 1;

        try {
            app(ActualizarPrecioProducto::class)->ejecutar(
                $producto,
                CalculadoraPrecio::aUnidadSinIsv((float) ($datos['costo_inicial'] ?? 0), $producto->gravado, $unidades),
                MotivoCambioPrecio::Compra,
                $usuario,
                CalculadoraPrecio::aUnidadSinIsv((float) ($datos['precio_inicial'] ?? 0), $producto->gravado, $unidades),
                'Producto nuevo',
            );
        } catch (ReglaNegocioException $e) {
            Notification::make()->title('El producto se creó, pero falta su precio')->body($e->getMessage())->warning()->send();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
