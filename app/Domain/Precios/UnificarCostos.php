<?php

declare(strict_types=1);

namespace App\Domain\Precios;

use App\Enums\MotivoCambioPrecio;
use App\Models\Existencia;
use App\Models\Presentacion;
use App\Models\Producto;

/**
 * Pasa los productos de un costo por presentación a UN costo por unidad
 * (una vez, con la migración 2026_11_20_100000):
 *
 *  - El costo por unidad es el promedio de lo que hay en existencia (todas
 *    las bodegas, todas las presentaciones con costo), pesado por unidades.
 *    Sin existencia, el de la base; si la base no tiene, el más alto por
 *    unidad de las otras (Producto::costoReferencia).
 *  - Cada presentación queda con ese × lo que trae y su precio "Con %" sale
 *    de ahí; el fijo se queda (ActualizarPrecioProducto, que lo deja en el
 *    historial como "Costo por unidad").
 *
 * Los productos que ya cuadran o que no tienen costo no se tocan.
 */
final class UnificarCostos
{
    public const NOTA = 'UN COSTO POR UNIDAD';

    public function __construct(private readonly ActualizarPrecioProducto $precios) {}

    /**
     * Cuántos productos cambiaron.
     */
    public function ejecutar(): int
    {
        $cambiados = 0;

        foreach (Producto::query()->with(['categoria', 'presentaciones.unidadMedida'])->lazyById(200) as $producto) {
            if ($this->producto($producto)) {
                $cambiados++;
            }
        }

        return $cambiados;
    }

    /**
     * Deja un producto con un costo por unidad; false si ya cuadraba o no tiene costo.
     */
    public function producto(Producto $producto): bool
    {
        $unidad = self::costoPorUnidad($producto);

        if ($unidad <= 0) {
            return false;
        }

        $cuadra = $producto->presentaciones->every(
            fn (Presentacion $presentacion): bool => abs($presentacion->costo - round($unidad * max(1, $presentacion->contenido), 4)) < 0.0001,
        );

        if ($cuadra) {
            return false;
        }

        $base = $producto->presentacionBase();

        $this->precios->ejecutar($producto, MotivoCambioPrecio::CostoUnico, nota: self::NOTA, costos: [$base->id => $unidad]);

        return true;
    }

    /**
     * El costo por unidad (sin ISV) que le toca: el de lo que hay, pesado por unidades.
     */
    public static function costoPorUnidad(Producto $producto): float
    {
        $hay = Existencia::query()
            ->where('producto_id', $producto->id)
            ->where('cantidad', '>', 0)
            ->groupBy('presentacion_id')
            ->selectRaw('presentacion_id, SUM(cantidad) AS cantidad')
            ->pluck('cantidad', 'presentacion_id');

        $unidades = 0;
        $valor = 0.0;

        foreach ($producto->presentaciones as $presentacion) {
            $cuantas = (int) ($hay[$presentacion->id] ?? 0);

            if ($cuantas > 0 && $presentacion->costo > 0) {
                $unidades += $cuantas * max(1, $presentacion->contenido);
                $valor += $cuantas * $presentacion->costo;
            }
        }

        return $unidades > 0 ? $valor / $unidades : $producto->costoReferencia();
    }
}
