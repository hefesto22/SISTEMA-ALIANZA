<?php

declare(strict_types=1);

namespace App\Domain\Inventario;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Domain\Precios\ActualizarPrecioProducto;
use App\Domain\Precios\CalculadoraPrecio;
use App\Enums\MetodoCosto;
use App\Enums\MotivoCambioPrecio;
use App\Enums\TipoMovimiento;
use App\Models\Bodega;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\Configuracion;
use App\Models\Existencia;
use App\Models\Presentacion;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Entra una compra de un proveedor a una bodega. Cada línea es un producto
 * en una de sus presentaciones (CJ de 8, CJ de 12, UN…). Por cada línea:
 *
 * 1. El costo de la factura (de la presentación completa, con ISV si el
 *    producto es gravado) se pasa a costo por unidad base sin ISV, como se
 *    guarda el producto.
 * 2. El costo nuevo del producto sale según Configuración:
 *      - promedio: (lo que hay en todas las bodegas × costo actual + lo que
 *        entra × costo de compra) ÷ total. Si no había nada, el de compra.
 *      - último: el de compra.
 * 3. El precio de cada presentación se recalcula con el margen del producto
 *    (o de su categoría) por ActualizarPrecioProducto, que deja el historial.
 *    Así el precio siempre cubre el costo. Las de precio fijo (puesto a
 *    mano) se quedan; solo cambia el costo.
 * 4. Suma a la existencia de ESA bodega (en unidades base: 3 CJ de 12 = 36)
 *    y deja el renglón en el kárdex.
 *
 * El costo y el precio son uno solo para todas las bodegas; la existencia y
 * las compras son de cada bodega.
 */
final class RegistrarCompra
{
    public function __construct(
        private readonly ActualizarPrecioProducto $precios,
        private readonly MoverExistencia $mover,
    ) {}

    /**
     * @param list<array{producto_id: int, presentacion_id: int, cantidad: int, costo: float}> $lineas costo = lo que dice la factura por la presentación completa, con ISV si es gravado
     */
    public function ejecutar(
        Bodega $bodega,
        Proveedor $proveedor,
        array $lineas,
        User $usuario,
        ?string $facturaProveedor = null,
        ?Carbon $fecha = null,
        ?string $notas = null,
    ): Compra {
        if ($lineas === []) {
            throw new ReglaNegocioException('Agregue al menos un producto a la compra.');
        }

        return DB::transaction(function () use ($bodega, $proveedor, $lineas, $usuario, $facturaProveedor, $fecha, $notas): Compra {
            $compra = Compra::query()->create([
                'bodega_id'         => $bodega->id,
                'proveedor_id'      => $proveedor->id,
                'factura_proveedor' => filled($facturaProveedor) ? trim((string) $facturaProveedor) : null,
                'fecha'             => ($fecha ?? now())->toDateString(),
                'notas'             => filled($notas) ? trim((string) $notas) : null,
                'user_id'           => $usuario->id,
            ]);

            $metodo = Configuracion::actual()->metodo_costo;
            $subtotal = 0.0;
            $isv = 0.0;

            // En orden de producto, como los despachos: así una compra y un
            // despacho al mismo tiempo bloquean en el mismo orden.
            usort($lineas, fn (array $a, array $b): int => $a['producto_id'] <=> $b['producto_id']);

            foreach ($lineas as $linea) {
                $item = $this->linea($compra, $bodega, $proveedor, $linea, $metodo, $usuario);
                $subtotal += $item->subtotal;
                $isv += $item->isv;
            }

            $compra->forceFill([
                'subtotal' => round($subtotal, 2),
                'isv'      => round($isv, 2),
                'total'    => round($subtotal + $isv, 2),
            ])->save();

            return $compra;
        });
    }

    /**
     * Costo por unidad sin ISV después de la compra.
     */
    public static function costoNuevo(MetodoCosto $metodo, int $existencia, float $costoActual, int $unidades, float $costoCompra): float
    {
        if ($metodo === MetodoCosto::Ultimo || $existencia <= 0) {
            return round($costoCompra, 4);
        }

        return round(($existencia * $costoActual + $unidades * $costoCompra) / ($existencia + $unidades), 4);
    }

    /**
     * @param array{producto_id: int, presentacion_id: int, cantidad: int, costo: float} $linea
     */
    private function linea(Compra $compra, Bodega $bodega, Proveedor $proveedor, array $linea, MetodoCosto $metodo, User $usuario): CompraItem
    {
        $producto = Producto::query()->with('categoria')->lockForUpdate()->findOrFail($linea['producto_id']);

        if ((int) $producto->proveedor_id !== (int) $proveedor->id) {
            throw new ReglaNegocioException("{$producto->etiqueta()} no es de {$proveedor->etiqueta()}.");
        }

        $presentacion = Presentacion::query()->with('unidadMedida')->whereKey($linea['presentacion_id'])->where('producto_id', $producto->id)->first();

        if (! $presentacion instanceof Presentacion || ! $presentacion->activo) {
            throw new ReglaNegocioException("Escoja en qué presentación viene {$producto->etiqueta()}.");
        }

        $cantidad = $linea['cantidad'];
        $costoIngresado = round($linea['costo'], 4);

        if ($cantidad < 1) {
            throw new ReglaNegocioException("La cantidad de {$producto->etiqueta()} debe ser de 1 en adelante.");
        }

        if ($costoIngresado <= 0) {
            throw new ReglaNegocioException("El costo de {$producto->etiqueta()} debe ser mayor que cero.");
        }

        $unidades = $cantidad * $presentacion->contenido;
        $costoUnidad = CalculadoraPrecio::aUnidadSinIsv($costoIngresado, $producto->gravado, $presentacion->contenido);

        // Lo que hay en todas las bodegas antes de esta línea: el costo es uno solo.
        $hay = (int) Existencia::query()->where('producto_id', $producto->id)->sum('cantidad');
        $costoAnterior = $producto->precio_actualizado_at === null ? null : $producto->costo_unidad;
        $costoNuevo = self::costoNuevo($metodo, $hay, $producto->costo_unidad, $unidades, $costoUnidad);

        $this->precios->ejecutar($producto, $costoNuevo, MotivoCambioPrecio::Compra, $usuario, nota: "COMPRA {$compra->numero} · {$bodega->nombre}");
        $producto->refresh();
        $presentacion->refresh();

        $this->mover->ejecutar($bodega->id, $producto, $unidades, TipoMovimiento::Compra, $usuario, compraId: $compra->id, costoUnidad: $costoUnidad, nota: "COMPRA {$compra->numero}");

        // El total de la línea es el de la factura; el ISV sale de ahí.
        $total = round($costoIngresado * $cantidad, 2);
        $subtotal = $producto->gravado ? round($total / (1 + CalculadoraPrecio::tasaIsv()), 2) : $total;

        return CompraItem::query()->create([
            'compra_id'       => $compra->id,
            'producto_id'     => $producto->id,
            'presentacion_id' => $presentacion->id,
            'cantidad'        => $cantidad,
            'unidades'        => $unidades,
            'costo_ingresado' => $costoIngresado,
            'costo_unidad'    => $costoUnidad,
            'gravado'         => $producto->gravado,
            'subtotal'        => $subtotal,
            'isv'             => round($total - $subtotal, 2),
            'total'           => $total,
            'costo_anterior'  => $costoAnterior,
            'costo_nuevo'     => $producto->costo_unidad,
            'precio_nuevo'    => $presentacion->precio,
        ]);
    }
}
