<?php

declare(strict_types=1);

namespace App\Domain\Pedidos;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Models\Pedido;
use App\Models\PedidoItem;

/**
 * Pone en cada línea cuánto sale (entre 0 y lo que se pidió). Si al final
 * no sale nada, no se deja: para eso se anula el pedido.
 */
final class AjustarLineas
{
    /**
     * @param array<int, int|string|null> $sale [id de línea => cantidad que sale]
     * @param bool $faltaSaleCompleta si una línea no viene: true = sale completa (validar), false = se queda como estaba (corregir)
     */
    public static function aplicar(Pedido $pedido, array $sale, bool $faltaSaleCompleta): void
    {
        $alguna = false;

        /** @var PedidoItem $item */
        foreach ($pedido->items as $item) {
            if (array_key_exists($item->id, $sale) && $sale[$item->id] !== null) {
                $cantidad = (int) $sale[$item->id];
            } else {
                $cantidad = $faltaSaleCompleta ? $item->cantidad : $item->cantidadFacturable();
            }

            $item->cantidad_despachada = max(0, min($cantidad, $item->cantidad));
            $item->save();

            $alguna = $alguna || $item->cantidad_despachada > 0;
        }

        if (! $alguna) {
            throw new ReglaNegocioException("Al pedido {$pedido->numero} no le sale ningún producto. Si no hay nada, anúlelo.");
        }
    }
}
