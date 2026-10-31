<?php

declare(strict_types=1);

namespace App\Domain\Inventario;

use App\Enums\EstadoPedido;
use App\Enums\UnidadVenta;
use App\Models\Existencia;
use App\Models\PedidoItem;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lo que todavía se puede vender de un producto en una bodega: lo que hay
 * menos lo que ya está apartado en pedidos pendientes. Los pendientes no
 * sacan de la bodega (sale al validar), pero ya están prometidos: así el
 * vendedor no vende dos veces lo mismo.
 *
 * Todo en UNIDADES. Sin bodega (administración sin escoger) es lo de todas.
 * $sinPedido deja fuera las líneas de ese pedido (el que se está editando:
 * lo suyo no cuenta contra él).
 */
final class Disponible
{
    /**
     * [producto_id => unidades en la bodega].
     *
     * @param array<mixed> $productoIds
     *
     * @return array<int, int>
     */
    public static function existencia(?int $bodegaId, array $productoIds): array
    {
        if ($productoIds === []) {
            return [];
        }

        /** @var array<int, int> $hay */
        $hay = Existencia::query()
            ->whereIn('producto_id', $productoIds)
            ->when($bodegaId !== null, fn (Builder $query): Builder => $query->where('bodega_id', $bodegaId))
            ->groupBy('producto_id')
            ->selectRaw('producto_id, SUM(cantidad) as total')
            ->pluck('total', 'producto_id')
            ->map(fn (mixed $cantidad): int => (int) $cantidad)
            ->all();

        return $hay;
    }

    /**
     * [producto_id => unidades apartadas en pedidos pendientes de la bodega].
     *
     * @param array<mixed> $productoIds
     *
     * @return array<int, int>
     */
    public static function apartado(?int $bodegaId, array $productoIds, ?int $sinPedido = null): array
    {
        if ($productoIds === []) {
            return [];
        }

        /** @var array<int, int> $apartado */
        $apartado = self::pendientes($bodegaId, $sinPedido)
            ->join('productos', 'productos.id', '=', 'pedido_items.producto_id')
            ->whereIn('pedido_items.producto_id', $productoIds)
            ->groupBy('pedido_items.producto_id')
            ->selectRaw('pedido_items.producto_id, '.self::unidadesSql().' as total', [UnidadVenta::Caja->value])
            ->pluck('total', 'producto_id')
            ->map(fn (mixed $cantidad): int => (int) $cantidad)
            ->all();

        return $apartado;
    }

    /**
     * [producto_id => existencia − apartado].
     *
     * @param array<mixed> $productoIds
     *
     * @return array<int, int>
     */
    public static function en(?int $bodegaId, array $productoIds, ?int $sinPedido = null): array
    {
        $hay = self::existencia($bodegaId, $productoIds);
        $apartado = self::apartado($bodegaId, $productoIds, $sinPedido);
        $disponible = [];

        foreach ($productoIds as $id) {
            if (is_numeric($id)) {
                $disponible[(int) $id] = ($hay[(int) $id] ?? 0) - ($apartado[(int) $id] ?? 0);
            }
        }

        return $disponible;
    }

    /**
     * Le agrega a una consulta de productos las columnas existencia_bodega y
     * apartado (en unidades), para ordenar o filtrar por lo disponible.
     *
     * @param Builder<Producto> $productos
     *
     * @return Builder<Producto>
     */
    public static function conColumnas(Builder $productos, ?int $bodegaId, ?int $sinPedido = null): Builder
    {
        $existencia = Existencia::query()
            ->selectRaw('COALESCE(SUM(existencias.cantidad), 0)')
            ->whereColumn('existencias.producto_id', 'productos.id')
            ->when($bodegaId !== null, fn (Builder $query): Builder => $query->where('existencias.bodega_id', $bodegaId));

        $apartado = self::pendientes($bodegaId, $sinPedido)
            ->selectRaw('COALESCE('.self::unidadesSql().', 0)', [UnidadVenta::Caja->value])
            ->whereColumn('pedido_items.producto_id', 'productos.id');

        return $productos
            ->select('productos.*')
            ->selectSub($existencia, 'existencia_bodega')
            ->selectSub($apartado, 'apartado');
    }

    /**
     * Líneas de pedidos pendientes de la bodega (sin las de $sinPedido).
     *
     * @return Builder<PedidoItem>
     */
    private static function pendientes(?int $bodegaId, ?int $sinPedido): Builder
    {
        return PedidoItem::query()
            ->join('pedidos', 'pedidos.id', '=', 'pedido_items.pedido_id')
            ->where('pedidos.estado', EstadoPedido::Pendiente)
            ->when($bodegaId !== null, fn (Builder $query): Builder => $query->where('pedidos.bodega_id', $bodegaId))
            ->when($sinPedido !== null, fn (Builder $query): Builder => $query->where('pedidos.id', '!=', $sinPedido));
    }

    /**
     * Suma en unidades: la caja cuenta sus unidades (lleva un ? para 'caja').
     */
    private static function unidadesSql(): string
    {
        return 'SUM(CASE WHEN pedido_items.unidad = ? THEN pedido_items.cantidad * productos.unidades_por_caja ELSE pedido_items.cantidad END)';
    }
}
