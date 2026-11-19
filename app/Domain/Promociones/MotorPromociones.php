<?php

declare(strict_types=1);

namespace App\Domain\Promociones;

use App\Models\Promocion;

/**
 * Calcula las promociones de un pedido. No guarda nada: recibe las líneas
 * que se cobran (las bonificaciones no cuentan) y las promociones con sus
 * condiciones.
 *
 * Reglas:
 *  - La condición es una lista de productos, cada uno con su cantidad en una
 *    presentación EXACTA ("2 CJ18 de X + 1 CJ24 de Y"), como las arma el
 *    proveedor: 2 CJ18 no se llenan con una CJ24 ni con 36 sueltas.
 *    Veces que aplica = la menor de (lo que trae de esa presentación ÷ lo que pide).
 *  - Gratis (100 %): es una bonificación. El regalo (veces × su cantidad, en
 *    su presentación) va en `regalos` y RecalcularPedido lo agrega solo en
 *    su propia línea; no tiene que venir en el pedido. "Compre 2 y lleve 1":
 *    con 2 pagadas se agrega 1.
 *  - Con descuento (a mitad o con un %): se descuenta de las líneas de la
 *    presentación del regalo que ya vienen en el pedido, en orden, hasta
 *    veces × su cantidad. Si el regalo es de la condición ("compre 2 y lleve
 *    la 3a a mitad"), por cada vez tienen que venir las 2 y la 3a. Si falta,
 *    queda como aviso para el vendedor.
 *  - Una línea recibe a lo sumo un descuento; las promociones se aplican en el orden recibido.
 *  - Descuento = precio sin ISV × las que cubre × % (gratis = 100 %, mitad = 50 %).
 */
final class MotorPromociones
{
    /**
     * @param array<LineaPromocion> $lineas
     * @param iterable<Promocion> $promociones
     */
    public function calcular(array $lineas, iterable $promociones): ResultadoPromociones
    {
        $hay = [];

        foreach ($lineas as $linea) {
            $hay[$linea->presentacionId] = ($hay[$linea->presentacionId] ?? 0) + max(0, $linea->cantidad);
        }

        $descuentos = [];
        $faltantes = [];
        $regalos = [];

        foreach ($promociones as $promocion) {
            $requerido = self::requerido($promocion, $hay);

            if ($requerido === null || $promocion->cantidad_beneficio <= 0 || $promocion->descuento_porcentaje <= 0) {
                continue;
            }

            $regalo = $promocion->beneficioPresentacion;
            $porRegalo = $promocion->cantidad_beneficio;

            if ($promocion->esBonificacion()) {
                $veces = self::veces($requerido, $hay);

                if ($veces > 0) {
                    $regalos[] = new RegaloPromocion($promocion, $regalo, $veces * $porRegalo);
                }

                continue;
            }

            $enCondicion = array_key_exists($regalo->id, $requerido);

            if (! array_key_exists($regalo->id, $hay)) {
                // Lo que se descuenta no viene en el pedido: solo se avisa.
                $veces = self::veces($requerido, $hay);

                if ($veces > 0) {
                    $faltantes[] = new FaltantePromocion($promocion, $veces * $porRegalo, $regalo);
                }

                continue;
            }

            $veces = self::veces($requerido, $hay, $enCondicion ? [$regalo->id => $porRegalo] : []);
            $porAplicar = $veces * $porRegalo;

            foreach ($lineas as $linea) {
                if ($porAplicar === 0) {
                    break;
                }

                if ($linea->presentacionId !== $regalo->id || isset($descuentos[$linea->clave]) || $linea->cantidad <= 0) {
                    continue;
                }

                $toma = min($porAplicar, $linea->cantidad);
                $descuentos[$linea->clave] = new DescuentoLinea($linea->clave, $promocion, self::monto($linea, $toma, $promocion->descuento_porcentaje), $toma);
                $porAplicar -= $toma;
            }

            if ($porAplicar > 0) {
                $faltantes[] = new FaltantePromocion($promocion, $porAplicar, $regalo);
            } elseif ($enCondicion) {
                // "Compre 2 y lleve la 3a a mitad": si ya lleva las 2 del siguiente grupo, solo le falta la 3a.
                $falta = self::faltaParaSiguiente($requerido, $hay, $regalo->id, $porRegalo, $veces);

                if ($falta > 0) {
                    $faltantes[] = new FaltantePromocion($promocion, $falta, $regalo);
                }
            }
        }

        return new ResultadoPromociones($descuentos, $faltantes, $regalos);
    }

    /**
     * Lo que pide cada presentación de la condición por cada vez que aplica.
     * null si la promoción no tiene condición válida o si alguna no viene en el pedido.
     *
     * @param array<int, int> $hay [presentacion_id => cuántas trae el pedido]
     *
     * @return array<int, int>|null
     */
    private static function requerido(Promocion $promocion, array $hay): ?array
    {
        $requerido = [];

        foreach ($promocion->condiciones as $condicion) {
            if (! array_key_exists($condicion->presentacion_id, $hay) || $condicion->cantidad <= 0) {
                return null;
            }

            $requerido[$condicion->presentacion_id] = ($requerido[$condicion->presentacion_id] ?? 0) + $condicion->cantidad;
        }

        return $requerido === [] ? null : $requerido;
    }

    /**
     * @param array<int, int> $requerido
     * @param array<int, int> $hay
     * @param array<int, int> $ademas lo que cada vez pide de más una presentación (el regalo con descuento que es de la condición)
     */
    private static function veces(array $requerido, array $hay, array $ademas = []): int
    {
        $veces = PHP_INT_MAX;

        foreach ($requerido as $presentacionId => $porVez) {
            $veces = min($veces, intdiv($hay[$presentacionId] ?? 0, $porVez + ($ademas[$presentacionId] ?? 0)));
        }

        return $veces;
    }

    /**
     * Cuántas del regalo faltan para el siguiente grupo, si lo demás ya está completo; 0 si no.
     *
     * @param array<int, int> $requerido
     * @param array<int, int> $hay
     */
    private static function faltaParaSiguiente(array $requerido, array $hay, int $regaloId, int $porRegalo, int $veces): int
    {
        $siguiente = $veces + 1;

        foreach ($requerido as $presentacionId => $porVez) {
            $necesita = $siguiente * $porVez + ($presentacionId === $regaloId ? $veces * $porRegalo : 0);

            if (($hay[$presentacionId] ?? 0) < $necesita) {
                return 0;
            }
        }

        return $siguiente * ($requerido[$regaloId] + $porRegalo) - ($hay[$regaloId] ?? 0);
    }

    private static function monto(LineaPromocion $linea, int $cantidad, float $porcentaje): float
    {
        $bruto = $linea->precio * $cantidad;
        $descuento = $porcentaje >= 100 ? round($bruto, 2) : round($bruto * $porcentaje / 100, 2);

        return min(round($linea->precio * $linea->cantidad, 2), $descuento);
    }
}
