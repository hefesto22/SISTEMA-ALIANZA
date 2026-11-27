<?php

declare(strict_types=1);

namespace App\Domain\Pedidos;

use App\Models\Existencia;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Presentacion;

/**
 * Con "controlar existencias": cuánto alcanza a salir de cada línea de un
 * pedido con lo que hay en su bodega. Es lo que trae de entrada el modal de
 * Validar (LineasPedido::datos) y lo que manda "Validar las N" cuando a un
 * pedido no le alcanza todo (ValidarVarios).
 *
 * Primero las cajas (cada una de sus cajas, en orden de línea) y después las
 * sueltas: de las sueltas y, si no alcanzan, de las cajas que sobraron (se
 * abren al validar). Los regalos no van: salen solos con lo que sale de lo
 * que se cobra (RecalcularPedido).
 */
final class LoQueAlcanza
{
    /**
     * [id de línea => lo que alcanza a salir] de las líneas que se cobran.
     *
     * @return array<int, int>
     */
    public static function delPedido(Pedido $pedido): array
    {
        $items = $pedido->items()->with(['producto.presentaciones', 'presentacion'])->where('bonificacion', false)->orderBy('id')->get();
        $sale = [];

        foreach ($items as $item) {
            $sale[$item->id] = $item->cantidadFacturable();
        }

        return self::deLasLineas(array_values($items->all()), $sale, self::existencias($pedido->bodega_id, $items->pluck('producto_id')->unique()->values()->all()));
    }

    /**
     * @param list<PedidoItem> $items con presentacion y producto.presentaciones
     * @param array<int, int> $sale [id de línea => lo que pidió]
     * @param array<int, int> $existencias [presentacion_id => lo que hay en la bodega]
     *
     * @return array<int, int>
     */
    public static function deLasLineas(array $items, array $sale, array $existencias): array
    {
        $quedan = $existencias;

        foreach ($items as $item) {
            if (! $item->presentacion->es_base) {
                $sale[$item->id] = min($sale[$item->id], max(0, $quedan[$item->presentacion_id] ?? 0));
                $quedan[$item->presentacion_id] = ($quedan[$item->presentacion_id] ?? 0) - $sale[$item->id];
            }
        }

        foreach ($items as $item) {
            if ($item->presentacion->es_base) {
                $sueltas = max(0, $quedan[$item->presentacion_id] ?? 0) + (int) $item->producto->presentaciones
                    ->filter(fn (Presentacion $caja): bool => ! $caja->es_base)
                    ->sum(fn (Presentacion $caja): int => max(0, $quedan[$caja->id] ?? 0) * $caja->contenido);

                $sale[$item->id] = min($sale[$item->id], $sueltas);
                $quedan[$item->presentacion_id] = ($quedan[$item->presentacion_id] ?? 0) - $sale[$item->id];

                // Lo que no hubo suelto sale de abrir cajas: ya no quedan para otra línea.
                $falta = max(0, -($quedan[$item->presentacion_id] ?? 0));

                foreach ($item->producto->presentaciones->filter(fn (Presentacion $caja): bool => ! $caja->es_base)->sortBy('contenido') as $caja) {
                    while ($falta > 0 && ($quedan[$caja->id] ?? 0) > 0) {
                        $quedan[$caja->id]--;
                        $quedan[$item->presentacion_id] += $caja->contenido;
                        $falta = max(0, -$quedan[$item->presentacion_id]);
                    }
                }
            }
        }

        return $sale;
    }

    /**
     * [presentacion_id => lo que hay en la bodega] de esos productos.
     *
     * @param array<mixed> $productoIds
     *
     * @return array<int, int>
     */
    public static function existencias(int $bodegaId, array $productoIds): array
    {
        if ($productoIds === []) {
            return [];
        }

        /** @var array<int, int> $hay */
        $hay = Existencia::query()
            ->where('bodega_id', $bodegaId)
            ->whereIn('producto_id', $productoIds)
            ->pluck('cantidad', 'presentacion_id')
            ->map(fn (mixed $cantidad): int => (int) $cantidad)
            ->all();

        return $hay;
    }
}
