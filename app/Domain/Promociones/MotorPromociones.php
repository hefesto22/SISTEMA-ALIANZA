<?php

declare(strict_types=1);

namespace App\Domain\Promociones;

use App\Enums\UnidadVenta;
use App\Models\Promocion;

/**
 * Calcula qué líneas de un pedido llevan descuento por promoción.
 * No guarda nada: recibe las líneas y las promociones (con sus condiciones).
 *
 * Reglas (todo se cuenta en unidades sueltas; una caja = sus UxC):
 *  - La condición es una lista de productos con su cantidad ("2 cajas de X + 1 caja de Y").
 *    Veces que aplica = la menor de (unidades compradas ÷ unidades pedidas) entre esos productos.
 *    Si el regalo es uno de ellos ("compre 2 y lleve 1"), a ese producto se le suma el regalo.
 *  - Se descuentan hasta (veces × cantidad de regalo) unidades de las líneas del producto
 *    de regalo, en el orden del pedido. El regalo tiene que venir en el pedido (bodega lo
 *    despacha); si falta, queda como aviso para el vendedor.
 *  - Una línea recibe a lo sumo una promoción; las promociones se aplican en el orden recibido.
 *  - Descuento = precio sin ISV × unidades con promoción × % (gratis = 100%, mitad = 50%).
 */
final class MotorPromociones
{
    /**
     * @param array<LineaPromocion> $lineas
     * @param iterable<Promocion> $promociones
     */
    public function calcular(array $lineas, iterable $promociones): ResultadoPromociones
    {
        $unidades = [];
        $uxc = [];

        foreach ($lineas as $linea) {
            $unidades[$linea->productoId] = ($unidades[$linea->productoId] ?? 0) + $linea->unidades();
            $uxc[$linea->productoId] = $linea->unidadesPorCaja;
        }

        $descuentos = [];
        $faltantes = [];

        foreach ($promociones as $promocion) {
            $requerido = self::requerido($promocion, $uxc);

            if ($requerido === null || $promocion->cantidad_beneficio <= 0 || $promocion->descuento_porcentaje <= 0) {
                continue;
            }

            $regaloId = $promocion->producto_beneficio_id;

            if (! array_key_exists($regaloId, $uxc)) {
                // El producto de regalo no viene en el pedido: solo se avisa.
                $veces = self::veces($requerido, $unidades, $regaloId, 0);

                if ($veces > 0) {
                    $faltantes[] = new FaltantePromocion($promocion, $veces * $promocion->cantidad_beneficio, $promocion->beneficio_unidad);
                }

                continue;
            }

            $porRegalo = $promocion->cantidad_beneficio * self::factor($promocion->beneficio_unidad, $uxc[$regaloId]);
            $veces = self::veces($requerido, $unidades, $regaloId, $porRegalo);
            $porAplicar = $veces * $porRegalo;

            foreach ($lineas as $linea) {
                if ($porAplicar === 0) {
                    break;
                }

                if ($linea->productoId !== $regaloId || isset($descuentos[$linea->clave]) || $linea->unidades() === 0) {
                    continue;
                }

                $toma = min($porAplicar, $linea->unidades());
                $descuentos[$linea->clave] = new DescuentoLinea($linea->clave, $promocion, self::monto($linea, $toma, $promocion->descuento_porcentaje), $toma);
                $porAplicar -= $toma;
            }

            if ($porAplicar > 0) {
                $faltantes[] = self::faltante($promocion, $porAplicar, $uxc[$regaloId]);
            } elseif (array_key_exists($regaloId, $requerido)) {
                // "Compre 2 y lleve 1": si ya lleva lo que se paga del siguiente grupo, solo le falta el regalo.
                $falta = self::faltaParaSiguiente($requerido, $unidades, $regaloId, $porRegalo, $veces);

                if ($falta > 0) {
                    $faltantes[] = self::faltante($promocion, $falta, $uxc[$regaloId]);
                }
            }
        }

        return new ResultadoPromociones($descuentos, $faltantes);
    }

    public static function factor(UnidadVenta $unidad, int $unidadesPorCaja): int
    {
        return $unidad === UnidadVenta::Caja ? max(1, $unidadesPorCaja) : 1;
    }

    /**
     * Unidades sueltas que pide cada producto de la condición por cada vez que aplica.
     * null si la promoción no tiene condición válida o si falta en el pedido algún producto.
     *
     * @param array<int, int> $uxc
     *
     * @return array<int, int>|null
     */
    private static function requerido(Promocion $promocion, array $uxc): ?array
    {
        $requerido = [];

        foreach ($promocion->condiciones as $condicion) {
            if (! array_key_exists($condicion->producto_id, $uxc) || $condicion->cantidad <= 0) {
                return null;
            }

            $requerido[$condicion->producto_id] = ($requerido[$condicion->producto_id] ?? 0)
                + $condicion->cantidad * self::factor($condicion->unidad, $uxc[$condicion->producto_id]);
        }

        return $requerido === [] ? null : $requerido;
    }

    /**
     * @param array<int, int> $requerido
     * @param array<int, int> $unidades
     */
    private static function veces(array $requerido, array $unidades, int $regaloId, int $porRegalo): int
    {
        $veces = PHP_INT_MAX;

        foreach ($requerido as $productoId => $porVez) {
            $porVez += $productoId === $regaloId ? $porRegalo : 0;
            $veces = min($veces, intdiv($unidades[$productoId] ?? 0, $porVez));
        }

        return $veces;
    }

    /**
     * Cuántas unidades del regalo faltan para el siguiente grupo, si lo demás ya está completo; 0 si no.
     *
     * @param array<int, int> $requerido
     * @param array<int, int> $unidades
     */
    private static function faltaParaSiguiente(array $requerido, array $unidades, int $regaloId, int $porRegalo, int $veces): int
    {
        $siguiente = $veces + 1;

        foreach ($requerido as $productoId => $porVez) {
            $necesita = $siguiente * $porVez + ($productoId === $regaloId ? $veces * $porRegalo : 0);

            if (($unidades[$productoId] ?? 0) < $necesita) {
                return 0;
            }
        }

        return $siguiente * ($requerido[$regaloId] + $porRegalo) - ($unidades[$regaloId] ?? 0);
    }

    private static function monto(LineaPromocion $linea, int $unidades, float $porcentaje): float
    {
        // Si la promoción cubre la línea completa se usa la cantidad tal cual,
        // así "gratis" deja la línea exactamente en cero.
        $bruto = $unidades === $linea->unidades()
            ? $linea->precio * $linea->cantidad
            : $linea->precio * $unidades / $linea->factor();

        $descuento = $porcentaje >= 100 ? round($bruto, 2) : round($bruto * $porcentaje / 100, 2);

        return min(round($linea->precio * $linea->cantidad, 2), $descuento);
    }

    private static function faltante(Promocion $promocion, int $unidades, int $uxcRegalo): FaltantePromocion
    {
        if ($promocion->beneficio_unidad === UnidadVenta::Caja && $uxcRegalo > 1 && $unidades % $uxcRegalo === 0) {
            return new FaltantePromocion($promocion, intdiv($unidades, $uxcRegalo), UnidadVenta::Caja);
        }

        return new FaltantePromocion($promocion, $unidades, UnidadVenta::Unidad);
    }
}
