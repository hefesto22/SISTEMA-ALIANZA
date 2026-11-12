<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos\Pages;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Precios\ActualizarPrecioProducto;
use App\Domain\Precios\CalculadoraPrecio;
use App\Domain\Productos\GuardarPresentaciones;
use App\Enums\MotivoCambioPrecio;
use App\Filament\Resources\Productos\ProductoResource;
use App\Filament\Resources\Productos\Schemas\ProductoForm;
use App\Models\Presentacion;
use App\Models\Producto;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateProducto extends CreateRecord
{
    protected static string $resource = ProductoResource::class;

    /**
     * Antes de crear: que las presentaciones no se repitan y traigan 2 o más.
     */
    protected function beforeCreate(): void
    {
        $datos = $this->data ?? [];
        $error = GuardarPresentaciones::revisar((int) ($datos['unidad_medida_id'] ?? 0), ProductoForm::otras($datos));

        if ($error !== null) {
            Notification::make()->title($error)->danger()->send();

            $this->halt();
        }
    }

    /**
     * Con el producto ya creado se guardan sus presentaciones y, si se
     * escribió un costo o algún precio, se cargan por la misma puerta de
     * siempre para que el historial arranque desde aquí.
     *
     * El costo se toma de la presentación más grande que lo trae escrito
     * (costo con ISV de la presentación completa → por unidad base sin ISV).
     * Un precio escrito es el de esa presentación; las demás salen del
     * costo con el % de utilidad (sin costo, quedan sin precio hasta la
     * primera compra).
     */
    protected function afterCreate(): void
    {
        /** @var Producto $producto */
        $producto = $this->getRecord();
        $datos = $this->data ?? [];
        $otras = ProductoForm::otras($datos);

        /** @var User|null $usuario */
        $usuario = Auth::user();

        try {
            $guardadas = app(GuardarPresentaciones::class)->ejecutar($producto, (bool) ($datos['base_activa'] ?? true), $otras);

            /** @var list<array{presentacion: Presentacion, costo: float|null, precio: float|null}> $filas */
            $filas = [[
                'presentacion' => $guardadas['base'],
                'costo'        => ProductoForm::monto($datos['base_costo'] ?? null),
                'precio'       => ProductoForm::monto($datos['base_precio'] ?? null),
            ]];

            foreach ($guardadas['otras'] as $i => $presentacion) {
                $filas[] = ['presentacion' => $presentacion, 'costo' => $otras[$i]['costo'], 'precio' => $otras[$i]['precio']];
            }

            $costo = self::costoUnidad($filas, $producto->gravado);
            $precios = [];

            foreach ($filas as $fila) {
                if ($fila['precio'] !== null) {
                    $precios[$fila['presentacion']->id] = ['precio' => CalculadoraPrecio::aUnidadSinIsv($fila['precio'], $producto->gravado)];
                }
            }

            if ($costo !== null || $precios !== []) {
                $producto->refresh();

                app(ActualizarPrecioProducto::class)->ejecutar(
                    $producto,
                    $costo ?? 0.0,
                    MotivoCambioPrecio::Compra,
                    $usuario,
                    $precios,
                    'Producto nuevo',
                );
            }
        } catch (ReglaNegocioException $e) {
            Notification::make()->title('El producto se creó, pero falta revisar sus presentaciones o precios')->body($e->getMessage())->warning()->send();
        }
    }

    /**
     * Costo por unidad base sin ISV: de la presentación más grande que trae costo escrito.
     *
     * @param list<array{presentacion: Presentacion, costo: float|null, precio: float|null}> $filas
     */
    private static function costoUnidad(array $filas, bool $gravado): ?float
    {
        $conCosto = array_filter($filas, fn (array $fila): bool => $fila['costo'] !== null);

        if ($conCosto === []) {
            return null;
        }

        usort($conCosto, fn (array $a, array $b): int => $b['presentacion']->contenido <=> $a['presentacion']->contenido);

        return CalculadoraPrecio::aUnidadSinIsv((float) $conCosto[0]['costo'], $gravado, $conCosto[0]['presentacion']->contenido);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
