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
     * Cada presentación queda con el costo que se le escribió (con ISV, de
     * la presentación completa → sin ISV); las que no lo traen, con uno
     * estimado por unidad (el de la base si se escribió; si no, el más alto
     * por unidad de las otras) × lo que traen, hasta su primera compra. Un
     * precio escrito es el de esa presentación; las demás salen de su costo
     * con el % de utilidad (sin costo, quedan sin precio hasta la primera
     * compra).
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

            $costos = self::costos($filas, $producto->gravado);
            $precios = [];

            foreach ($filas as $fila) {
                if ($fila['precio'] !== null) {
                    $precios[$fila['presentacion']->id] = ['precio' => CalculadoraPrecio::aUnidadSinIsv($fila['precio'], $producto->gravado)];
                }
            }

            if ($costos !== [] || $precios !== []) {
                $producto->refresh();

                app(ActualizarPrecioProducto::class)->ejecutar(
                    $producto,
                    MotivoCambioPrecio::Compra,
                    $usuario,
                    $precios,
                    'Producto nuevo',
                    $costos,
                );
            }
        } catch (ReglaNegocioException $e) {
            Notification::make()->title('El producto se creó, pero falta revisar sus presentaciones o precios')->body($e->getMessage())->warning()->send();
        }
    }

    /**
     * El costo sin ISV de cada presentación: el escrito o, si no tiene, el
     * estimado por unidad × lo que trae. [] si no se escribió ninguno.
     *
     * @param list<array{presentacion: Presentacion, costo: float|null, precio: float|null}> $filas
     *
     * @return array<int, float>
     */
    private static function costos(array $filas, bool $gravado): array
    {
        $escritos = [];
        $porUnidad = [];

        foreach ($filas as $fila) {
            if ($fila['costo'] !== null) {
                $presentacion = $fila['presentacion'];
                $escritos[$presentacion->id] = CalculadoraPrecio::aUnidadSinIsv((float) $fila['costo'], $gravado);
                $porUnidad[$presentacion->es_base ? 'base' : $presentacion->id] = $escritos[$presentacion->id] / max(1, $presentacion->contenido);
            }
        }

        if ($escritos === []) {
            return [];
        }

        $referencia = $porUnidad['base'] ?? max($porUnidad);
        $costos = [];

        foreach ($filas as $fila) {
            $presentacion = $fila['presentacion'];
            $costos[$presentacion->id] = $escritos[$presentacion->id] ?? round($referencia * $presentacion->contenido, 4);
        }

        return $costos;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
