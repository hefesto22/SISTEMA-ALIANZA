<?php

declare(strict_types=1);

namespace App\Domain\Pedidos;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Enums\UnidadVenta;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Producto;

/**
 * Pone en cada línea cuánto sale: lo que diga bodega, de 0 en adelante.
 * Puede ser menos de lo pedido (no alcanzó, algo se arruinó) o más (el
 * cliente pidió más); la línea guarda lo que se pidió, así queda dicho.
 *
 * Bodega también puede mandar un producto del pedido en la otra unidad (en
 * unidades si una caja vino dañada, o en caja) y agregar un producto que no
 * venía en el pedido (activo): esa línea entra nueva con "pidió 0". Si
 * después se deja en 0, se quita. Si al final no sale nada, no se deja:
 * para eso se anula el pedido.
 */
final class AjustarLineas
{
    /** Más que esto en una línea es un error de dedo. */
    public const MAXIMO = 99999;

    /**
     * @param array<int, int|string|null> $sale [id de línea => cantidad que sale]
     * @param bool $faltaSaleCompleta si una línea no viene: true = sale completa (validar), false = se queda como estaba (corregir)
     * @param list<array{producto_id: int, unidad: UnidadVenta, cantidad: int}> $nuevas líneas nuevas: un producto del pedido en la unidad que no traía o uno que no venía
     */
    public static function aplicar(Pedido $pedido, array $sale, bool $faltaSaleCompleta, array $nuevas = []): void
    {
        $items = $pedido->items()->with('producto')->get();

        foreach ($nuevas as $nueva) {
            if ($nueva['cantidad'] < 1) {
                continue;
            }

            $linea = $items->first(fn (PedidoItem $item): bool => $item->producto_id === $nueva['producto_id'] && $item->unidad === $nueva['unidad']);

            // Ya traía esa unidad: es la misma línea.
            if ($linea instanceof PedidoItem) {
                $sale[$linea->id] = $nueva['cantidad'];

                continue;
            }

            $delPedido = $items->first(fn (PedidoItem $item): bool => $item->producto_id === $nueva['producto_id']);
            $producto = $delPedido instanceof PedidoItem ? $delPedido->producto : self::paraAgregar($pedido, $nueva['producto_id']);

            $creada = self::nueva($pedido, $producto, $nueva['unidad']);
            $items->push($creada);
            $sale[$creada->id] = $nueva['cantidad'];
        }

        $alguna = false;

        foreach ($items as $item) {
            if (array_key_exists($item->id, $sale) && $sale[$item->id] !== null) {
                $cantidad = (int) $sale[$item->id];
            } elseif ($item->cantidad_despachada === null && $item->cantidad === 0) {
                $cantidad = 0;
            } else {
                $cantidad = $faltaSaleCompleta ? $item->cantidad : $item->cantidadFacturable();
            }

            $item->cantidad_despachada = max(0, min($cantidad, self::MAXIMO));

            // Una que se agregó al validar y ya no sale: se quita.
            if ($item->cantidad === 0 && $item->cantidad_despachada === 0) {
                $item->delete();

                continue;
            }

            $item->save();

            $alguna = $alguna || $item->cantidad_despachada > 0;
        }

        $pedido->unsetRelation('items');

        if (! $alguna) {
            throw new ReglaNegocioException("Al pedido {$pedido->numero} no le sale ningún producto. Si no hay nada, anúlelo.");
        }
    }

    /**
     * Un producto que no venía en el pedido: tiene que existir y estar activo.
     */
    private static function paraAgregar(Pedido $pedido, int $productoId): Producto
    {
        $producto = Producto::query()->find($productoId);

        if (! $producto instanceof Producto) {
            throw new ReglaNegocioException("El producto que se quiere agregar al pedido {$pedido->numero} ya no existe.");
        }

        if (! $producto->activo) {
            throw new ReglaNegocioException("{$producto->descripcion} no está activo: no se puede agregar al pedido {$pedido->numero}.");
        }

        return $producto;
    }

    /**
     * Línea nueva "pidió 0", con el precio de hoy (al validar se recalcula
     * igual que las demás; al corregir se queda este).
     */
    private static function nueva(Pedido $pedido, Producto $producto, UnidadVenta $unidad): PedidoItem
    {
        $item = new PedidoItem;
        $item->forceFill([
            'pedido_id'   => $pedido->id,
            'producto_id' => $producto->id,
            'unidad'      => $unidad,
            'cantidad'    => 0,
            'precio'      => $producto->precioPara($unidad),
            'gravado'     => $producto->gravado,
        ])->save();

        return $item->setRelation('producto', $producto);
    }
}
