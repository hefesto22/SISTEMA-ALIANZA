<?php

declare(strict_types=1);

namespace App\Domain\Promociones;

use App\Models\Promocion;

/**
 * Calcula qué líneas de un pedido llevan descuento por promoción.
 * No guarda nada: recibe las líneas y las promociones (con sus condiciones).
 *
 * Reglas (todo se cuenta en unidades base; una CJ de 12 = 12):
 *  - La condición es una lista de productos con su cantidad, cada uno en una
 *    presentación ("2 cajas de 12 de X + 1 caja de 24 de Y").
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
        $enPedido = [];

        foreach ($lineas as $linea) {
            $unidades[$linea->productoId] = ($unidades[$linea->productoId] ?? 0) + $linea->unidades();
            $enPedido[$linea->productoId] = true;
        }

        $descuentos = [];
        $faltantes = [];

        foreach ($promociones as $promocion) {
            $requerido = self::requerido($promocion, $enPedido);

            if ($requerido === null || $promocion->cantidad_beneficio <= 0 || $promocion->descuento_porcentaje <= 0) {
                continue;
            }

            $regaloId = $promocion->producto_beneficio_id;

            if (! array_key_exists($regaloId, $enPedido)) {
                // El producto de regalo no viene en el pedido: solo se avisa.
                $veces = self::veces($requerido, $unidades, $regaloId, 0);

                if ($veces > 0) {
                    $faltantes[] = new FaltantePromocion($promocion, $veces * $promocion->cantidad_beneficio, $promocion->beneficioPresentacion);
                }

                continue;
            }

            $porRegalo = $promocion->cantidad_beneficio * max(1, $promocion->beneficioPresentacion->contenido);
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
                $faltantes[] = self::faltante($promocion, $porAplicar);
            } elseif (array_key_exists($regaloId, $requerido)) {
                // "Compre 2 y lleve 1": si ya lleva lo que se paga del siguiente grupo, solo le falta el regalo.
                $falta = self::faltaParaSiguiente($requerido, $unidades, $regaloId, $porRegalo, $veces);

                if ($falta > 0) {
                    $faltantes[] = self::faltante($promocion, $falta);
                }
            }
        }

        return new ResultadoPromociones($descuentos, $faltantes);
    }

    /**
     * Unidades base que pide cada producto de la condición por cada vez que aplica.
     * null si la promoción no tiene condición válida o si falta en el pedido algún producto.
     *
     * @param array<int, true> $enPedido
     *
     * @return array<int, int>|null
     */
    private static function requerido(Promocion $promocion, array $enPedido): ?array
    {
        $requerido = [];

        foreach ($promocion->condiciones as $condicion) {
            if (! array_key_exists($condicion->producto_id, $enPedido) || $condicion->cantidad <= 0) {
                return null;
            }

            $requerido[$condicion->producto_id] = ($requerido[$condicion->producto_id] ?? 0)
                + $condicion->cantidad * max(1, $condicion->presentacion->contenido);
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

    /**
     * Lo que falta del regalo, en su presentación si da cabal; si no, en la base.
     */
    private static function faltante(Promocion $promocion, int $unidades): FaltantePromocion
    {
        $regalo = $promocion->beneficioPresentacion;

        if ($regalo->contenido > 1 && $unidades % $regalo->contenido === 0) {
            return new FaltantePromocion($promocion, intdiv($unidades, $regalo->contenido), $regalo);
        }

        return new FaltantePromocion($promocion, $unidades, $promocion->productoBeneficio->presentacionBase());
    }
}
