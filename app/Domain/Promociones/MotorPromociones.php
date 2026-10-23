<?php

declare(strict_types=1);

namespace App\Domain\Promociones;

use App\Enums\UnidadVenta;
use App\Models\Promocion;

/**
 * Calcula qué líneas de un pedido llevan descuento por promoción.
 * No toca la base de datos: recibe las líneas y las promociones a aplicar.
 *
 * Reglas (todo se cuenta en unidades sueltas; una caja = sus UxC):
 *  - Veces que aplica = unidades compradas del producto condición ÷ las que pide la promoción.
 *    Si condición y regalo son el mismo producto ("compre 2 y lleve 1"): ÷ (condición + regalo).
 *  - Se descuentan hasta (veces × cantidad de regalo) unidades de las líneas del producto
 *    de regalo, en el orden del pedido. El producto de regalo tiene que venir en el pedido
 *    (bodega lo tiene que despachar); si falta, queda como aviso para el vendedor.
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
            $compradas = $unidades[$promocion->producto_condicion_id] ?? 0;
            $porCondicion = $promocion->cantidad_condicion * self::factor($promocion->condicion_unidad, $uxc[$promocion->producto_condicion_id] ?? 1);

            if ($compradas === 0 || $porCondicion <= 0 || $promocion->cantidad_beneficio <= 0 || $promocion->descuento_porcentaje <= 0) {
                continue;
            }

            if (! array_key_exists($promocion->producto_beneficio_id, $uxc)) {
                // El producto de regalo no viene en el pedido: solo se avisa.
                $veces = intdiv($compradas, $porCondicion);

                if ($veces > 0) {
                    $faltantes[] = new FaltantePromocion($promocion, $veces * $promocion->cantidad_beneficio, $promocion->beneficio_unidad);
                }

                continue;
            }

            $uxcBeneficio = $uxc[$promocion->producto_beneficio_id];
            $porBeneficio = $promocion->cantidad_beneficio * self::factor($promocion->beneficio_unidad, $uxcBeneficio);
            $porGrupo = $promocion->esMismoProducto() ? $porCondicion + $porBeneficio : $porCondicion;
            $veces = intdiv($compradas, $porGrupo);
            $porAplicar = $veces * $porBeneficio;

            foreach ($lineas as $linea) {
                if ($porAplicar === 0) {
                    break;
                }

                if ($linea->productoId !== $promocion->producto_beneficio_id || isset($descuentos[$linea->clave]) || $linea->unidades() === 0) {
                    continue;
                }

                $toma = min($porAplicar, $linea->unidades());
                $descuentos[$linea->clave] = new DescuentoLinea($linea->clave, $promocion, self::monto($linea, $toma, $promocion->descuento_porcentaje), $toma);
                $porAplicar -= $toma;
            }

            if ($porAplicar > 0) {
                $faltantes[] = self::faltante($promocion, $porAplicar, $uxcBeneficio);
            } elseif ($promocion->esMismoProducto() && $compradas % $porGrupo >= $porCondicion) {
                // Ya lleva lo de la condición otra vez: le falta poco para el siguiente regalo.
                $faltantes[] = self::faltante($promocion, $porGrupo - $compradas % $porGrupo, $uxcBeneficio);
            }
        }

        return new ResultadoPromociones($descuentos, $faltantes);
    }

    public static function factor(UnidadVenta $unidad, int $unidadesPorCaja): int
    {
        return $unidad === UnidadVenta::Caja ? max(1, $unidadesPorCaja) : 1;
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

    private static function faltante(Promocion $promocion, int $unidades, int $uxcBeneficio): FaltantePromocion
    {
        if ($promocion->beneficio_unidad === UnidadVenta::Caja && $uxcBeneficio > 1 && $unidades % $uxcBeneficio === 0) {
            return new FaltantePromocion($promocion, intdiv($unidades, $uxcBeneficio), UnidadVenta::Caja);
        }

        return new FaltantePromocion($promocion, $unidades, UnidadVenta::Unidad);
    }
}
