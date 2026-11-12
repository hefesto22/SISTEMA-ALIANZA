<?php

declare(strict_types=1);

namespace App\Domain\Pedidos;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Presentacion;

/**
 * Pone en cada línea cuánto sale: lo que diga bodega, de 0 en adelante.
 * Puede ser menos de lo pedido (no alcanzó, algo se arruinó) o más (el
 * cliente pidió más); la línea guarda lo que se pidió, así queda dicho.
 *
 * Bodega también puede mandar un producto del pedido en otra de sus
 * presentaciones (en unidades si una caja vino dañada, o en otra caja) y
 * agregar un producto que no venía en el pedido (activo): esa línea entra
 * nueva con "pidió 0". Si después se deja en 0, se quita. Si al final no
 * sale nada, no se deja: para eso se anula el pedido.
 */
final class AjustarLineas
{
    /** Más que esto en una línea es un error de dedo. */
    public const MAXIMO = 99999;

    /**
     * @param array<int, int|string|null> $sale [id de línea => cantidad que sale]
     * @param bool $faltaSaleCompleta si una línea no viene: true = sale completa (validar), false = se queda como estaba (corregir)
     * @param list<array{presentacion_id: int, cantidad: int}> $nuevas líneas nuevas: un producto del pedido en una presentación que no traía o uno que no venía
     */
    public static function aplicar(Pedido $pedido, array $sale, bool $faltaSaleCompleta, array $nuevas = []): void
    {
        $items = $pedido->items()->with('producto')->get();

        foreach ($nuevas as $nueva) {
            if ($nueva['cantidad'] < 1) {
                continue;
            }

            $linea = $items->first(fn (PedidoItem $item): bool => $item->presentacion_id === $nueva['presentacion_id']);

            // Ya traía esa presentación: es la misma línea.
            if ($linea instanceof PedidoItem) {
                $sale[$linea->id] = $nueva['cantidad'];

                continue;
            }

            $presentacion = self::paraAgregar($pedido, $nueva['presentacion_id'], array_values($items->map(fn (PedidoItem $item): int => $item->producto_id)->all()));

            $creada = self::nueva($pedido, $presentacion);
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
     * La presentación de una línea nueva: tiene que existir y venderse. Si el
     * producto no venía en el pedido, también tiene que estar activo.
     *
     * @param list<int> $delPedido productos que ya trae el pedido
     */
    private static function paraAgregar(Pedido $pedido, int $presentacionId, array $delPedido): Presentacion
    {
        $presentacion = Presentacion::query()->with(['producto', 'unidadMedida'])->find($presentacionId);

        if (! $presentacion instanceof Presentacion) {
            throw new ReglaNegocioException("El producto que se quiere agregar al pedido {$pedido->numero} ya no existe.");
        }

        $producto = $presentacion->producto;

        if (! $presentacion->activo) {
            throw new ReglaNegocioException("{$producto->descripcion} ya no se vende en {$presentacion->sigla()}.");
        }

        if (! in_array($producto->id, $delPedido, true) && ! $producto->activo) {
            throw new ReglaNegocioException("{$producto->descripcion} no está activo: no se puede agregar al pedido {$pedido->numero}.");
        }

        return $presentacion;
    }

    /**
     * Línea nueva "pidió 0", con el precio de hoy (al validar se recalcula
     * igual que las demás; al corregir se queda este).
     */
    private static function nueva(Pedido $pedido, Presentacion $presentacion): PedidoItem
    {
        $item = new PedidoItem;
        $item->forceFill([
            'pedido_id'       => $pedido->id,
            'producto_id'     => $presentacion->producto_id,
            'presentacion_id' => $presentacion->id,
            'cantidad'        => 0,
            'precio'          => $presentacion->precio,
            'gravado'         => $presentacion->producto->gravado,
        ])->save();

        return $item->setRelation('producto', $presentacion->producto);
    }
}
