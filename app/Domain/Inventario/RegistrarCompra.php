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
 * en una de sus presentaciones (CJ de 48, CJ de 32, UN…); un mismo
 * producto puede venir en varias en la misma compra. Por cada línea:
 *
 * 1. El costo de la factura (de la presentación completa, con ISV si el
 *    producto es gravado) se pasa a sin ISV, como se guarda.
 * 2. El costo nuevo de ESA presentación sale según Configuración:
 *      - promedio: (lo que hay de ella en todas las bodegas × su costo +
 *        lo que entra × costo de compra) ÷ total. Si no había, el de compra.
 *      - último: el de compra.
 *    Las otras presentaciones del producto no se tocan: una caja de 48 que
 *    se compró más barata no le baja el costo a la de 32 ni a las sueltas.
 * 3. El precio de esa presentación se recalcula con su % de utilidad
 *    (ActualizarPrecioProducto, que deja el historial): siempre cubre su
 *    costo. Si es de precio fijo se queda; solo cambia el costo.
 * 4. Suma a la existencia de esa presentación en ESA bodega y deja el
 *    renglón en el kárdex.
 *
 * El costo y el precio son los mismos para todas las bodegas; la
 * existencia y las compras son de cada bodega.
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

            // En orden de producto y presentación, como los despachos: así una
            // compra y un despacho al mismo tiempo bloquean en el mismo orden.
            usort($lineas, fn (array $a, array $b): int => [$a['producto_id'], $a['presentacion_id']] <=> [$b['producto_id'], $b['presentacion_id']]);

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
     * Costo (sin ISV) con que queda una presentación cuando le entran
     * $entran a $costoEntra y había $existencia (en todas las bodegas) a
     * $costoActual. Sirve igual para una compra y para las sueltas que salen
     * de una caja abierta.
     */
    public static function costoNuevo(MetodoCosto $metodo, int $existencia, float $costoActual, int $entran, float $costoEntra): float
    {
        if ($metodo === MetodoCosto::Ultimo || $existencia <= 0) {
            return round($costoEntra, 4);
        }

        return round(($existencia * $costoActual + $entran * $costoEntra) / ($existencia + $entran), 4);
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
        $costoCompra = CalculadoraPrecio::aUnidadSinIsv($costoIngresado, $producto->gravado);

        // Lo que hay de ESTA presentación en todas las bodegas antes de esta
        // línea (su costo es uno solo para todas).
        $hay = (int) Existencia::query()->where('presentacion_id', $presentacion->id)->sum('cantidad');
        $costoAnterior = $producto->precio_actualizado_at === null ? null : $presentacion->costo;
        $costoNuevo = self::costoNuevo($metodo, $hay, $presentacion->costo, $cantidad, $costoCompra);

        $this->precios->ejecutar($producto, MotivoCambioPrecio::Compra, $usuario, nota: "COMPRA {$compra->numero} · {$bodega->nombre}", costos: [$presentacion->id => $costoNuevo]);
        $presentacion->refresh();
        $presentacion->setRelation('producto', $producto);

        $this->mover->ejecutar($bodega->id, $presentacion, $cantidad, TipoMovimiento::Compra, $usuario, compraId: $compra->id, costo: $costoCompra, nota: "COMPRA {$compra->numero}");

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
            'costo_unidad'    => round($costoCompra / max(1, $presentacion->contenido), 4),
            'gravado'         => $producto->gravado,
            'subtotal'        => $subtotal,
            'isv'             => round($total - $subtotal, 2),
            'total'           => $total,
            'costo_anterior'  => $costoAnterior,
            'costo_nuevo'     => $presentacion->costo,
            'precio_nuevo'    => $presentacion->precio,
        ]);
    }
}
