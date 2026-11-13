<?php

declare(strict_types=1);

namespace App\Domain\Inventario;

use App\Enums\EstadoPedido;
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
 * La existencia es de cada presentación (porPresentacion()): una CJ48 solo
 * sale de las CJ48; las unidades sueltas salen de las sueltas y, si no
 * alcanzan, de abrir cajas (faltantes()). existencia(), apartado() y en()
 * dan el total del producto en unidades base. Sin bodega (administración
 * sin escoger) es lo de todas. $sinPedido deja fuera las líneas de ese
 * pedido (el que se está editando: lo suyo no cuenta contra él).
 */
final class Disponible
{
    /** Suma en unidades base: cada línea, lo pedido × lo que trae su presentación. */
    private const UNIDADES = 'SUM(pedido_items.cantidad * pedido_items.contenido)';

    /**
     * [producto_id => unidades base en la bodega] (cada presentación × lo que trae).
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
            ->join('presentaciones', 'presentaciones.id', '=', 'existencias.presentacion_id')
            ->whereIn('existencias.producto_id', $productoIds)
            ->when($bodegaId !== null, fn (Builder $query): Builder => $query->where('existencias.bodega_id', $bodegaId))
            ->groupBy('existencias.producto_id')
            ->selectRaw('existencias.producto_id, SUM(existencias.cantidad * presentaciones.contenido) as total')
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
            ->whereIn('pedido_items.producto_id', $productoIds)
            ->groupBy('pedido_items.producto_id')
            ->selectRaw('pedido_items.producto_id, '.self::UNIDADES.' as total')
            ->pluck('total', 'producto_id')
            ->map(fn (mixed $cantidad): int => (int) $cantidad)
            ->all();

        return $apartado;
    }

    /**
     * [producto_id => existencia − apartado], en unidades base.
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
     * Lo disponible de cada presentación, en ella: [producto_id =>
     * [presentacion_id => lo que hay − lo apartado]]. Las que no tienen
     * existencia ni nada apartado no salen.
     *
     * @param array<mixed> $productoIds
     *
     * @return array<int, array<int, int>>
     */
    public static function porPresentacion(?int $bodegaId, array $productoIds, ?int $sinPedido = null): array
    {
        if ($productoIds === []) {
            return [];
        }

        $disponible = [];

        $hay = Existencia::query()
            ->whereIn('producto_id', $productoIds)
            ->when($bodegaId !== null, fn (Builder $query): Builder => $query->where('bodega_id', $bodegaId))
            ->groupBy('producto_id', 'presentacion_id')
            ->selectRaw('producto_id, presentacion_id, SUM(cantidad) as total')
            ->orderBy('presentacion_id')
            ->toBase()
            ->get();

        foreach ($hay as $fila) {
            $disponible[(int) $fila->producto_id][(int) $fila->presentacion_id] = (int) $fila->total;
        }

        $apartado = self::pendientes($bodegaId, $sinPedido)
            ->whereIn('pedido_items.producto_id', $productoIds)
            ->groupBy('pedido_items.producto_id', 'pedido_items.presentacion_id')
            ->selectRaw('pedido_items.producto_id, pedido_items.presentacion_id, SUM(pedido_items.cantidad) as total')
            ->orderBy('pedido_items.presentacion_id')
            ->toBase()
            ->get();

        foreach ($apartado as $fila) {
            $producto = (int) $fila->producto_id;
            $presentacion = (int) $fila->presentacion_id;
            $disponible[$producto][$presentacion] = ($disponible[$producto][$presentacion] ?? 0) - (int) $fila->total;
        }

        return $disponible;
    }

    /**
     * Lo que no alcanza de lo que se pide de UN producto: cada caja sale de
     * sus cajas; las sueltas (la base), de las sueltas y, si no alcanzan,
     * de abrir las cajas que sobran. [presentacion_id => cuánto falta, en
     * ella]; vacío si alcanza todo.
     *
     * @param array<int, int> $pide [presentacion_id => cantidad]
     * @param array<int, int> $hay [presentacion_id => disponible]
     * @param array<int, int> $contenido [presentacion_id => lo que trae]
     *
     * @return array<int, int>
     */
    public static function faltantes(array $pide, array $hay, array $contenido, int $baseId): array
    {
        $faltan = [];
        $deCajas = 0;

        foreach ($contenido as $id => $trae) {
            if ($id === $baseId) {
                continue;
            }

            $falta = ($pide[$id] ?? 0) - ($hay[$id] ?? 0);

            if ($falta > 0) {
                $faltan[$id] = $falta;
            } else {
                $deCajas += -$falta * max(1, $trae);
            }
        }

        // Las sueltas disponibles pueden estar en negativo (otros pedidos ya
        // apartaron más de las que hay): eso también sale de abrir cajas.
        $sueltas = ($pide[$baseId] ?? 0) - ($hay[$baseId] ?? 0) - $deCajas;

        if (($pide[$baseId] ?? 0) > 0 && $sueltas > 0) {
            $faltan[$baseId] = $sueltas;
        }

        return $faltan;
    }

    /**
     * Le agrega a una consulta de productos las columnas existencia_bodega y
     * apartado (en unidades base), para ordenar o filtrar por lo disponible.
     *
     * @param Builder<Producto> $productos
     *
     * @return Builder<Producto>
     */
    public static function conColumnas(Builder $productos, ?int $bodegaId, ?int $sinPedido = null): Builder
    {
        $existencia = Existencia::query()
            ->join('presentaciones as pe', 'pe.id', '=', 'existencias.presentacion_id')
            ->selectRaw('COALESCE(SUM(existencias.cantidad * pe.contenido), 0)')
            ->whereColumn('existencias.producto_id', 'productos.id')
            ->when($bodegaId !== null, fn (Builder $query): Builder => $query->where('existencias.bodega_id', $bodegaId));

        $apartado = self::pendientes($bodegaId, $sinPedido)
            ->selectRaw('COALESCE('.self::UNIDADES.', 0)')
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
}
