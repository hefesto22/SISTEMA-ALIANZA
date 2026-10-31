<?php

declare(strict_types=1);

namespace App\Domain\Pedidos;

use App\Domain\Exceptions\ReglaNegocioException;
use App\Models\Pedido;
use App\Models\PedidoItem;

/**
 * Pone en cada línea cuánto sale: lo que diga bodega, de 0 en adelante.
 * Puede ser menos de lo pedido (no alcanzó, algo se arruinó) o más (el
 * cliente pidió más); la línea guarda lo que se pidió, así queda dicho. Si
 * al final no sale nada, no se deja: para eso se anula el pedido.
 */
final class AjustarLineas
{
    /** Más que esto en una línea es un error de dedo. */
    public const MAXIMO = 99999;

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

            $item->cantidad_despachada = max(0, min($cantidad, self::MAXIMO));
            $item->save();

            $alguna = $alguna || $item->cantidad_despachada > 0;
        }

        if (! $alguna) {
            throw new ReglaNegocioException("Al pedido {$pedido->numero} no le sale ningún producto. Si no hay nada, anúlelo.");
        }
    }
}
