<?php

declare(strict_types=1);

namespace App\Domain\Promociones;

use App\Enums\EstadoPedido;
use App\Models\PedidoItem;
use App\Models\Promocion;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Cuánto se ha regalado de cada promoción con tope (las del plan del
 * proveedor): las unidades de sus bonificaciones en pedidos que no están
 * anulados, en la presentación del regalo.
 *
 *  - Entregadas: en pedidos ya impresos (facturados).
 *  - Comprometidas: en pedidos pendientes o por imprimir: se apartan desde
 *    que se guarda la preventa, para que dos vendedores no ofrezcan las
 *    mismas. Si el pedido se anula o se corrige, regresan.
 *  - Quedan: el tope menos las dos (nunca menos de 0).
 *
 * Validado, cuenta lo que sale (cantidad_despachada); si no, lo pedido.
 */
final class UsoDePromociones
{
    /**
     * Unidades de regalo que ya tomó cada promoción, sin contar las de
     * $sinPedido (el que se está calculando). [promocion_id => unidades]
     *
     * @param array<int> $promociones
     *
     * @return array<int, int>
     */
    public static function usadas(array $promociones, ?int $sinPedido = null): array
    {
        if ($promociones === []) {
            return [];
        }

        $usadas = [];

        foreach (self::consulta($promociones, $sinPedido)->selectRaw('pedido_items.promocion_id, SUM(COALESCE(pedido_items.cantidad_despachada, pedido_items.cantidad)) AS unidades')->groupBy('pedido_items.promocion_id')->get() as $fila) {
            $usadas[(int) $fila->promocion_id] = (int) $fila->unidades;
        }

        return $usadas;
    }

    /**
     * Entregadas y comprometidas de cada promoción.
     *
     * @param array<int> $promociones
     *
     * @return array<int, array{entregadas: int, comprometidas: int}>
     */
    public static function detalle(array $promociones): array
    {
        if ($promociones === []) {
            return [];
        }

        $detalle = [];
        $impreso = EstadoPedido::Impreso->value;

        $filas = self::consulta($promociones)
            ->selectRaw(
                'pedido_items.promocion_id, '
                .'SUM(CASE WHEN pedidos.estado = ? THEN COALESCE(pedido_items.cantidad_despachada, pedido_items.cantidad) ELSE 0 END) AS entregadas, '
                .'SUM(CASE WHEN pedidos.estado = ? THEN 0 ELSE COALESCE(pedido_items.cantidad_despachada, pedido_items.cantidad) END) AS comprometidas',
                [$impreso, $impreso],
            )
            ->groupBy('pedido_items.promocion_id')
            ->get();

        foreach ($filas as $fila) {
            $detalle[(int) $fila->promocion_id] = ['entregadas' => (int) $fila->entregadas, 'comprometidas' => (int) $fila->comprometidas];
        }

        return $detalle;
    }

    /**
     * Lo que queda de regalo en cada promoción con tope, sin contar lo de
     * $sinPedido. Las que no tienen tope no salen. [promocion_id => quedan]
     *
     * @param iterable<Promocion> $promociones
     *
     * @return array<int, int>
     */
    public static function disponibles(iterable $promociones, ?int $sinPedido = null): array
    {
        $topes = [];

        foreach ($promociones as $promocion) {
            if ($promocion->tieneTope()) {
                $topes[$promocion->id] = (int) $promocion->tope_unidades;
            }
        }

        $usadas = self::usadas(array_keys($topes), $sinPedido);
        $disponibles = [];

        foreach ($topes as $id => $tope) {
            $disponibles[$id] = max(0, $tope - ($usadas[$id] ?? 0));
        }

        return $disponibles;
    }

    /**
     * Las bonificaciones de esas promociones en pedidos que no están anulados.
     *
     * @param array<int> $promociones
     */
    private static function consulta(array $promociones, ?int $sinPedido = null): QueryBuilder
    {
        return PedidoItem::query()
            ->toBase()
            ->join('pedidos', 'pedidos.id', '=', 'pedido_items.pedido_id')
            ->whereIn('pedido_items.promocion_id', $promociones)
            ->where('pedido_items.bonificacion', true)
            ->where('pedidos.estado', '!=', EstadoPedido::Anulado->value)
            ->when($sinPedido !== null, fn (QueryBuilder $query): QueryBuilder => $query->where('pedido_items.pedido_id', '!=', $sinPedido));
    }
}
