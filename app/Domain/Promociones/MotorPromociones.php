<?php

declare(strict_types=1);

namespace App\Domain\Promociones;

use App\Models\Presentacion;
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
 *  - En unidades (las del plan del proveedor, "24+1"): cuenta las UNIDADES
 *    de todos sus productos juntos, en cualquier presentación (2 CJ12 = 24
 *    unidades; 12 de manzana + 12 de pera también). Veces = unidades ÷ N.
 *    Solo como bonificación (gratis).
 *  - Tope (lo que autorizó el proveedor): $disponibles dice cuánto le queda
 *    de regalo a cada promoción con tope. Si le toca más, se le da lo que
 *    queda y se avisa; si ya no queda nada, va en `agotadas`.
 */
final class MotorPromociones
{
    /**
     * @param array<LineaPromocion> $lineas
     * @param iterable<Promocion> $promociones
     * @param array<int, int> $disponibles [promocion_id => regalo que le queda] de las que tienen tope
     */
    public function calcular(array $lineas, iterable $promociones, array $disponibles = []): ResultadoPromociones
    {
        $hay = [];

        foreach ($lineas as $linea) {
            $hay[$linea->presentacionId] = ($hay[$linea->presentacionId] ?? 0) + max(0, $linea->cantidad);
        }

        $descuentos = [];
        $faltantes = [];
        $regalos = [];
        $agotadas = [];

        foreach ($promociones as $promocion) {
            if ($promocion->cantidad_beneficio <= 0 || $promocion->descuento_porcentaje <= 0) {
                continue;
            }

            $regalo = $promocion->beneficioPresentacion;
            $porRegalo = $promocion->cantidad_beneficio;

            if ($promocion->cuentaUnidades()) {
                if ($promocion->esBonificacion()) {
                    $ganadas = self::vecesEnUnidades($promocion, $lineas) * $porRegalo;
                    self::regalar($promocion, $regalo, $ganadas, $disponibles, $regalos, $agotadas);
                }

                continue;
            }

            $requerido = self::requerido($promocion, $hay);

            if ($requerido === null) {
                continue;
            }

            if ($promocion->esBonificacion()) {
                self::regalar($promocion, $regalo, self::veces($requerido, $hay) * $porRegalo, $disponibles, $regalos, $agotadas);

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

        return new ResultadoPromociones($descuentos, $faltantes, $regalos, $agotadas);
    }

    /**
     * El regalo que le toca ($ganadas), hasta lo que le queda si tiene tope.
     *
     * @param array<int, int> $disponibles
     * @param list<RegaloPromocion> $regalos
     * @param list<RegaloPromocion> $agotadas
     */
    private static function regalar(Promocion $promocion, Presentacion $regalo, int $ganadas, array $disponibles, array &$regalos, array &$agotadas): void
    {
        if ($ganadas <= 0) {
            return;
        }

        $da = array_key_exists($promocion->id, $disponibles) ? min($ganadas, max(0, $disponibles[$promocion->id])) : $ganadas;

        if ($da > 0) {
            $regalos[] = new RegaloPromocion($promocion, $regalo, $da, $ganadas);
        } else {
            $agotadas[] = new RegaloPromocion($promocion, $regalo, 0, $ganadas);
        }
    }

    /**
     * Veces que aplica contando unidades de todos sus productos juntos: unidades ÷ N.
     *
     * @param array<LineaPromocion> $lineas
     */
    private static function vecesEnUnidades(Promocion $promocion, array $lineas): int
    {
        $productos = [];
        $unidades = 0;

        foreach ($promocion->condiciones as $condicion) {
            $productos[$condicion->producto_id] = true;
        }

        foreach ($lineas as $linea) {
            if (isset($productos[$linea->productoId])) {
                $unidades += $linea->unidades();
            }
        }

        return intdiv($unidades, max(1, (int) $promocion->compra_unidades));
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
